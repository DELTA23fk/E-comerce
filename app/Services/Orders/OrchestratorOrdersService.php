<?php

namespace App\Services\Orders;

use App\Data\Pedidos\PedidoProveedorRequestData;
use App\Data\Pedidos\ProductoEnriquecidoData;
use App\Exceptions\Orders\ClientProfileNotFoundException;
use App\Exceptions\Orders\InvalidOrderStateException;
use App\Exceptions\Orders\ProductNotFoundException;
use App\Exceptions\Orders\ShippingException;
use App\Exceptions\Orders\ShippingOutOfRangeException;
use App\Exceptions\Orders\ShippingQuoteException;
use App\Factories\ProviderFactory;
use App\Models\Cliente;
use App\Models\DetallePedido;
use App\Models\Pedido;
use App\Models\PedidoProveedor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OrchestratorOrdersService
{
    public function __construct(
        private ProviderFactory $proveedorFactory
    ) {}

    /**
     * FASE 1: Crear pedido maestro sin procesar subpedidos
     * Se ejecuta antes del pago
     * 
     * @param array $datos ['productos' => [['clave' => 'XX', 'cantidad' => 2]], 'datos_envio' => [...]]
     * @throws ClientProfileNotFoundException
     * @throws ProductNotFoundException
     * @throws ShippingOutOfRangeException
     * @throws ShippingQuoteException
     */
    public function crearPedido(array $datos, string|int|null $almacenPreferido = null): Pedido
    {
        return DB::transaction(function () use ($datos, $almacenPreferido) {
            $user    = Auth::user();
            $cliente = $user->cliente;

            if (!$cliente) {
                throw new ClientProfileNotFoundException(Auth::id());
            }

            $productosBasicos      = $datos ?? [];
            $productosPorProveedor = $this->agruparProductosPorProveedor($productosBasicos);

            $todosLosProductosEnriquecidos = [];
            $totalProductos                = 0;
            $totalEnvio                    = 0;

            foreach ($productosPorProveedor as $proveedorId => $productosProveedor) {
                $proveedorService = $this->proveedorFactory->crear($proveedorId);

                $productosEnriquecidos = $proveedorService->enriquecerProductos($productosProveedor);

                // Pasar almacenPreferido — cada servicio lo acepta como opcional
                $proveedorService->validarDisponibilidad($productosEnriquecidos, $almacenPreferido);

                $cotizacion = $proveedorService->cotizarEnvio(
                    $productosEnriquecidos,
                    $cliente,
                    $almacenPreferido
                );

                $totalProductos += collect($productosEnriquecidos)->sum(fn($p) => $p->getSubtotal());
                $totalEnvio     += $cotizacion->montoTotal;

                $todosLosProductosEnriquecidos = array_merge(
                    $todosLosProductosEnriquecidos,
                    $productosEnriquecidos
                );
            }

            $pedido = Pedido::create([
                'folio'                   => $this->generarFolio(),
                'fecha_pedido'            => now(),
                'estatus'                 => 'pendiente_pago',
                'cliente_id'              => $cliente->id,
                'precio_total'            => $totalProductos + $totalEnvio,
                'precio_total_productos'  => $totalProductos,
                'precio_total_envio'      => $totalEnvio,
                'datos_envio'             => $datos['datos_envio']         ?? null,
                'almacen_preferido'       => $almacenPreferido,            // ← persiste para fase 2
            ]);

            $this->guardarProductosPendientes($pedido, $todosLosProductosEnriquecidos);

            return $pedido->fresh(['detalles']);
        });
    }

    /**
     * Cotizar envío de productos sin crear pedido
     * SOLO calcula el costo de envío, NO enriquece productos ni aplica ofertas
     * 
     * @param array $productos [['clave' => 'XX', 'cantidad' => 2], ...]
     * @param Cliente $cliente
     * @return array [
     *   'cotizaciones' => [
     *     ['proveedor_id' => 1, 'costo_envio' => 99.00, 'detalles' => [...]]
     *   ],
     *   'total_envio' => 99.00,
     *   'errores_proveedores' => []
     * ]
     * @throws ShippingOutOfRangeException
     * @throws ShippingQuoteException
     */
    public function cotizarEnvioProductos(
        array           $productos,
        Cliente         $cliente,
        string|int|null $almacenPreferido = null,
    ): array {
        $productosPorProveedor = $this->agruparProductosPorProveedor($productos);

        $cotizaciones       = [];
        $totalEnvio         = 0;
        $erroresProveedores = [];

        foreach ($productosPorProveedor as $proveedorId => $productosProveedor) {
            try {
                $proveedorService  = $this->proveedorFactory->crear($proveedorId);
                $productosBasicos  = $this->obtenerProductosBasicosParaCotizacion($productosProveedor);

                $cotizacion = $proveedorService->cotizarEnvio(
                    $productosBasicos,
                    $cliente,
                    $almacenPreferido  // ← propagado
                );

                $cotizaciones[] = [
                    'proveedor'   => $proveedorService->obtenerNombre(),
                    'costo_envio' => $cotizacion->montoTotal,
                    'detalles'    => [
                        'subtotal'    => $cotizacion->subtotal,
                        'iva'         => $cotizacion->iva,
                        'monto_total' => $cotizacion->montoTotal,
                        'adicional'   => $cotizacion->detalles,
                    ],
                ];

                $totalEnvio += $cotizacion->montoTotal;

            } catch (ShippingOutOfRangeException $e) {
                throw $e;

            } catch (ShippingQuoteException $e) {
                $erroresProveedores[] = [
                    'proveedor_id' => $proveedorId,
                    'error'        => $e->getMessage(),
                    'tipo'         => 'cotizacion',
                ];
                \Log::warning('Fallo cotización de envío con proveedor', [
                    'proveedor_id' => $proveedorId,
                    'error'        => $e->getMessage(),
                ]);

            } catch (\Exception $e) {
                $erroresProveedores[] = [
                    'proveedor_id' => $proveedorId,
                    'error'        => $e->getMessage(),
                    'tipo'         => 'inesperado',
                ];
                \Log::error('Error inesperado al cotizar envío', [
                    'proveedor_id' => $proveedorId,
                    'error'        => $e->getMessage(),
                ]);
            }
        }

        if (empty($cotizaciones) && !empty($erroresProveedores)) {
            throw new ShippingQuoteException(
                'Todos los proveedores',
                'No se pudo cotizar envío con ningún proveedor disponible',
                $productos
            );
        }

        return [
            'cotizaciones'       => $cotizaciones,
            'total_envio'        => round($totalEnvio, 2),
            'errores_proveedores' => $erroresProveedores,
        ];
    }

    /**
     * Obtener datos básicos de productos para cotización de envío
     * SIN enriquecer con ofertas, solo lo necesario para calcular envío
     * 
     * @param array $productosBasicos [['codigo_proveedor' => 'XX', 'cantidad' => 2], ...]
     * @return ProductoEnriquecidoData[]
     */
    private function obtenerProductosBasicosParaCotizacion(array $productosBasicos): array
    {
        $claves = collect($productosBasicos)->pluck('codigo_proveedor')->unique()->toArray();

        $productosDb = DB::table('proveedor_productos')
            ->whereIn('codigo_proveedor', $claves)
            ->select('id', 'codigo_proveedor', 'proveedor_id', 'producto_id', 'stock_total') // ← solo stock_total
            ->get()
            ->keyBy('codigo_proveedor');

        return collect($productosBasicos)->map(function ($productoBasico) use ($productosDb) {
            $proveedorProducto = $productosDb->get($productoBasico['codigo_proveedor']);

            if (!$proveedorProducto) {
                throw new ProductNotFoundException($productoBasico['codigo_proveedor']);
            }

            return new ProductoEnriquecidoData(
                proveedorProductoId: $proveedorProducto->id,
                codigoProveedor:     $proveedorProducto->codigo_proveedor,
                cantidad:            $productoBasico['cantidad'],
                precioUnitario:      0,
                precioOriginal:      0,
                proveedorId:         $proveedorProducto->proveedor_id,
                productoId:          $proveedorProducto->producto_id,
                enOferta:            false,
                descuentoPorcentaje: null,
                clavePromocion:      null,
                metadataProveedor:   [
                    'stock_total' => $proveedorProducto->stock_total, // ← solo campo resumen
                ]
            );
        })->toArray();
    }

    /**
     * FASE 2: Procesar subpedidos con proveedores después del pago
     */
    public function procesarPagoPedido(Pedido $pedido): bool
    {
        return DB::transaction(function () use ($pedido) {
            if ($pedido->estatus !== 'pendiente_pago') {
                throw new InvalidOrderStateException(
                    $pedido->id,
                    $pedido->estatus,
                    'pendiente_pago'
                );
            }

            $cliente = $pedido->cliente;
            
            // Obtener productos del pedido que no han sido procesados
            $detallesPendientes = $pedido->detalles()
                ->whereNull('pedido_proveedor_id')
                ->with('producto')
                ->get()
                ->toArray();

            if (empty($detallesPendientes)) {
                \Log::error('No hay productos pendientes para procesar', [
                    'pedido_id' => $pedido->id
                ]);
                return false;
            }

            // Agrupar detalles por proveedor
            $detallesPorProveedor = $this->agruparDetallesPorProveedor($detallesPendientes);

            $totalGeneral = 0;
            $totalProductos = 0;
            $totalEnvio = 0;
            $subpedidosExitosos = 0;
            $subpedidosFallidos = 0;
            $erroresDetallados = [];

            // Procesar cada subpedido - CONTINUAR AUNQUE FALLEN ALGUNOS
            foreach ($detallesPorProveedor as $proveedorId => $detalles) {
                $resultado = $this->procesarSubpedido(
                    $pedido,
                    $proveedorId,
                    $detalles,
                    $cliente,
                    $pedido->datos_envio
                );

                if ($resultado) {
                    // ÉXITO
                    $totalProductos += $resultado['subtotal'];
                    $totalEnvio += $resultado['envio'];
                    $totalGeneral += $resultado['total'];
                    $subpedidosExitosos++;
                    
                    \Log::info('Subpedido procesado exitosamente', [
                        'pedido_id' => $pedido->id,
                        'proveedor_id' => $proveedorId,
                        'folios' => $resultado['folios'],
                        'total' => $resultado['total']
                    ]);
                } else {
                    // FALLO
                    $subpedidosFallidos++;
                    
                    $error = [
                        'proveedor_id' => $proveedorId,
                        'productos_afectados' => collect($detalles)->pluck('clave_proveedor')->toArray(),
                        'timestamp' => now()->toDateTimeString()
                    ];
                    
                    $erroresDetallados[] = $error;
                    
                    $this->registrarSubpedidoFallido($pedido, $proveedorId, $detalles, $error);
                    
                    \Log::error('Subpedido falló - continuando con otros proveedores', [
                        'pedido_id' => $pedido->id,
                        'proveedor_id' => $proveedorId,
                        'productos_afectados' => $error['productos_afectados']
                    ]);
                }
            }

            // Determinar estatus final
            $estatusInfo = $this->determinarEstatusFinal(
                $subpedidosExitosos,
                $subpedidosFallidos,
                $erroresDetallados
            );

            // Actualizar pedido maestro
            $pedido->update([
                'estatus' => $estatusInfo['estatus'],
                'error_mensaje' => $estatusInfo['mensaje'],
                'errores_detallados' => $erroresDetallados ? json_encode($erroresDetallados) : null,
                'requiere_atencion_manual' => $subpedidosFallidos > 0,
            ]);

            if ($subpedidosFallidos > 0) {
                $this->notificarFallosParciales($pedido, $erroresDetallados);
            }

            return $estatusInfo['estatus'] === 'procesado';
        });
    }

    /**
     * Agrupar productos básicos por proveedor
     * Input: [['clave' => 'XX', 'cantidad' => 2], ...]
     * Output: [proveedorId => [['clave' => 'XX', 'cantidad' => 2], ...]]
     */
    private function agruparProductosPorProveedor(array $productosBasicos): array
    {
        $claves = collect($productosBasicos)->pluck('clave')->unique()->toArray();

        // Obtener el proveedor_id de cada código
        $mappingProveedores = \DB::table('proveedor_productos')
            ->whereIn('codigo_proveedor', $claves)
            ->select('codigo_proveedor', 'proveedor_id')
            ->get()
            ->keyBy('codigo_proveedor');

        $agrupados = [];
        
        foreach ($productosBasicos as $producto) {
            $mapping = $mappingProveedores->get($producto['clave']);
            
            if (!$mapping) {
                throw new ProductNotFoundException($producto['clave']);
            }

            $proveedorId = $mapping->proveedor_id;
            $agrupados[$proveedorId][] = [
                'codigo_proveedor' => $producto['clave'],
                'cantidad' => $producto['cantidad']
            ];
        }

        return $agrupados;
    }

    /**
     * Procesar un subpedido con un proveedor específico
     */
    private function procesarSubpedido(
        Pedido  $pedido,
        int     $proveedorId,
        array   $detalles,
        Cliente $cliente,
        ?array  $datosEnvio
    ): ?array {
        try {
            $proveedorService = $this->proveedorFactory->crear($proveedorId);

            $productos = collect($detalles)->map(fn($detalle) => [
                'proveedor_producto_id' => $detalle['proveedor_producto_id'],
                'codigo_proveedor'      => $detalle['clave_proveedor'],
                'cantidad'              => $detalle['cantidad'],
                'precio_unitario'       => $detalle['precio_unitario'],
            ])->toArray();

            $request = new PedidoProveedorRequestData(
                proveedorId:  $proveedorId,
                numeroOrden:  $pedido->folio,
                productos:    $productos,
                datosEnvio:   $datosEnvio,
                test:         config('app.env') !== 'production'
            );

            // Recuperar preferido persistido en fase 1
            $almacenPreferido = $pedido->almacen_preferido ?? null;

            $response = $proveedorService->crearPedido($request, $cliente, $almacenPreferido);

            if (!$response['success']) {
                \Log::error('Error al procesar subpedido', [
                    'pedido_id'    => $pedido->id,
                    'proveedor_id' => $proveedorId,
                    'error'        => $response['error'] ?? 'Error desconocido',
                ]);
                return null;
            }

            $result      = ['subtotal' => 0, 'envio' => 0, 'total' => 0, 'pedidos_proveedor_ids' => [], 'folios' => []];
            $pedidosData = $response['data'];

            if (!isset($pedidosData[0])) {
                $pedidosData = [$pedidosData];
            }

            foreach ($pedidosData as $pedidoData) {
                $pedidoProveedor = PedidoProveedor::create([
                    'pedido_id'               => $pedido->id,
                    'proveedor_id'            => $proveedorId,
                    'folio_pedido'            => $pedidoData['folioPedido'],
                    'precio_total_productos'  => $pedidoData['subtotal'],
                    'precio_total_envio'      => $pedidoData['flete']['monto_total'] ?? 0,
                    'precio_total'            => $pedidoData['total'],
                    'moneda'                  => $pedidoData['moneda']        ?? 'MXN',
                    'email_agente'            => $pedidoData['emailAgente']   ?? null,
                    'email_almacen'           => $pedidoData['emailAlmacen']  ?? null,
                    'envio_gratis'            => ($pedidoData['flete']['monto_total'] ?? 0) == 0,
                    'origen_envio'            => $pedidoData['origen']        ?? null,
                    'status'                  => 'creado',
                    'requiere_atencion_manual' => false,
                ]);

                $result['pedidos_proveedor_ids'][] = $pedidoProveedor->id;
                $result['folios'][]                 = $pedidoData['folioPedido'];
                $result['subtotal']                += $pedidoData['subtotal']                    ?? 0;
                $result['envio']                   += $pedidoData['flete']['monto_total']        ?? 0;
                $result['total']                   += $pedidoData['total']                       ?? 0;
            }

            $idsDetalles = collect($detalles)->pluck('id')->toArray();
            DetallePedido::whereIn('id', $idsDetalles)
                ->update(['pedido_proveedor_id' => $result['pedidos_proveedor_ids'][0]]);

            return $result;

        } catch (\Exception $e) {
            \Log::error('Excepción al procesar subpedido', [
                'pedido_id'    => $pedido->id,
                'proveedor_id' => $proveedorId,
                'exception'    => $e->getMessage(),
                'trace'        => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Agrupar detalles de pedido por proveedor
     */
    private function agruparDetallesPorProveedor(array $detalles): array
    {
        $agrupados = [];
        
        foreach ($detalles as $detalle) {
            // El producto ya viene cargado por el eager loading
            $proveedorId = $detalle['producto']['proveedor_id'];
            $agrupados[$proveedorId][] = $detalle;
        }

        return $agrupados;
    }

    /**
     * Guardar productos pendientes en DetallePedido
     */
    private function guardarProductosPendientes(Pedido $pedido, array $productos): void
    {
        $detalles = collect($productos)->map(function ($producto) use ($pedido) {
            return [
                'pedido_id' => $pedido->id,
                'pedido_proveedor_id' => null,
                'proveedor_producto_id' => $producto->proveedorProductoId,
                'clave_proveedor' => $producto->codigoProveedor,
                'cantidad' => $producto->cantidad,
                'precio_unitario' => $producto->precioUnitario,
                'subtotal' => $producto->getSubtotal(),
                'created_at' => now(),
                'updated_at' => now()
            ];
        });

        DetallePedido::insert($detalles->toArray());
    }

    private function registrarSubpedidoFallido(
        Pedido $pedido,
        int $proveedorId,
        array $detalles,
        array $error
    ): void 
    {
        try {
            PedidoProveedor::create([
                'pedido_id' => $pedido->id,
                'proveedor_id' => $proveedorId,
                'folio_pedido' => null,
                'precio_total_productos' => 0,
                'precio_total_envio' => 0,
                'precio_total' => 0,
                'moneda' => 'MXN',
                'status' => 'fallido',
                'error_mensaje' => 'Error al procesar con el proveedor',
                'error_detalle' => json_encode([
                    'detalles' => $detalles,
                    'error' => $error,
                    'requiere_reembolso' => true
                ]),
                'requiere_atencion_manual' => true
            ]);
        } catch (\Exception $e) {
            \Log::error('Error al registrar subpedido fallido', [
                'pedido_id' => $pedido->id,
                'proveedor_id' => $proveedorId,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function notificarFallosParciales(Pedido $pedido, array $errores): void
    {
        \Log::critical('ATENCIÓN REQUERIDA: Pedido con fallos parciales', [
            'pedido_id' => $pedido->id,
            'folio' => $pedido->folio,
            'cliente_id' => $pedido->cliente_id,
            'total_cobrado' => $pedido->precio_total,
            'errores' => $errores,
            'accion_requerida' => 'Revisar y procesar reembolso de productos no entregados'
        ]);
    }

    private function determinarEstatusFinal(int $exitosos, int $fallidos, array $errores): array
    {
        if ($exitosos > 0 && $fallidos === 0) {
            return [
                'estatus' => 'procesado',
                'mensaje' => 'Pedido procesado exitosamente'
            ];
        }

        if ($exitosos > 0 && $fallidos > 0) {
            return [
                'estatus' => 'procesado_parcial',
                'mensaje' => "Pedido parcialmente procesado. {$exitosos} proveedor(es) exitoso(s), {$fallidos} fallido(s). REQUIERE REEMBOLSO de productos no entregados."
            ];
        }

        return [
            'estatus' => 'fallido',
            'mensaje' => 'Pedido falló completamente. Todos los proveedores reportaron errores. REQUIERE REEMBOLSO TOTAL.'
        ];
    }

    private function generarFolio(): string
    {
        return 'NXTPED-' . now()->format('Ymd') . '-' . rand(1000, 9999);
    }
}