<?php

namespace App\Services\Orders;

use App\Data\Pedidos\PedidoProveedorRequestData;
use App\Exceptions\Orders\ClientProfileNotFoundException;
use App\Exceptions\Orders\InvalidOrderStateException;
use App\Exceptions\Orders\ProductNotFoundException;
use App\Exceptions\Orders\ShippingOutOfRangeException;
use App\Exceptions\Orders\ShippingQuoteException;
use App\Factories\ProviderFactory;
use App\Models\Cliente;
use App\Models\DetallePedido;
use App\Models\Pedido;
use App\Models\PedidoProveedor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orquestador de pedidos multi-proveedor.
 *
 * RESPONSABILIDADES (solo estas):
 *  - Agrupar productos por proveedor usando la tabla proveedor_productos como router.
 *  - Delegar enriquecimiento, validación, cotización y creación a cada ProveedorService.
 *  - Crear y actualizar el Pedido maestro y sus DetallePedido / PedidoProveedor.
 *  - Agregar resultados y manejar fallos parciales.
 *
 * NO HACE:
 *  - Lógica de stock, distribución por almacén ni cálculo de fletes (responsabilidad del proveedor).
 *  - Queries directas a tablas de stock o precios.
 *  - Lógica específica de ningún proveedor.
 */
class OrchestratorOrdersService
{
    public function __construct(
        private ProviderFactory $proveedorFactory,
    ) {}

    // =========================================================================
    // FASE 1 — Crear pedido maestro (antes del pago)
    // =========================================================================

    /**
     * Crea el pedido maestro sin procesar subpedidos con proveedores.
     * Calcula totales (productos + envío) y persiste DetallePedido en estado pendiente.
     *
     * @param  array            $datos             ['productos' => [['clave' => 'XX', 'cantidad' => 2]], 'datos_envio' => [...]], 'observaciones' => '...']
     * @param  string|int|null  $almacenPreferido  Se persiste en pedido para recuperarlo en Fase 2.
     *
     * @throws ClientProfileNotFoundException
     * @throws ProductNotFoundException
     * @throws ShippingOutOfRangeException
     * @throws ShippingQuoteException
     */
    public function crearPedido(array $datos, string|int|null $almacenPreferido = null): Pedido
    {
        return DB::transaction(function () use ($datos, $almacenPreferido) {
            $cliente = $this->resolverCliente();

            // Input normalizado: [['codigo_proveedor' => 'XX', 'cantidad' => 2], ...]
            $productosNormalizados = $this->normalizarProductos($datos['productos'] ?? []);
            $productosPorProveedor = $this->agruparProductosPorProveedor($productosNormalizados);

            $todosLosProductosEnriquecidos = [];
            $totalProductos                = 0;
            $totalEnvio                    = 0;

            foreach ($productosPorProveedor as $proveedorId => $productosDelProveedor) {
                $servicio = $this->proveedorFactory->crear($proveedorId);

                // El proveedor enriquece con precios y promociones propias
                $enriquecidos = $servicio->enriquecerProductos($productosDelProveedor);

                // El proveedor valida su propio stock (lanza excepción si falla)
                $servicio->validarDisponibilidad($enriquecidos, $almacenPreferido);

                // El proveedor calcula su propio costo de envío
                $cotizacion = $servicio->cotizarEnvio($enriquecidos, $cliente, $almacenPreferido);

                $totalProductos += collect($enriquecidos)->sum(fn($p) => $p->getSubtotal());
                $totalEnvio     += $cotizacion->montoTotal;

                $todosLosProductosEnriquecidos = array_merge($todosLosProductosEnriquecidos, $enriquecidos);
            }

            $pedido = Pedido::create([
                'folio'                  => $this->generarFolio(),
                'fecha_pedido'           => now(),
                'estatus'                => 'pendiente_pago',
                'cliente_id'             => $cliente->id,
                'precio_total'           => $totalProductos + $totalEnvio,
                'precio_total_productos' => $totalProductos,
                'precio_total_envio'     => $totalEnvio,
                'almacen_preferido'      => $almacenPreferido,   // persiste para Fase 2
                'observaciones'          => $datos['observaciones'] ?? null,
            ]);

            $this->guardarDetallesPendientes($pedido, $todosLosProductosEnriquecidos);

            return $pedido->fresh(['detalles']);
        });
    }

    // =========================================================================
    // FASE 2 — Procesar subpedidos después del pago
    // =========================================================================

    /**
     * Procesa los subpedidos con cada proveedor una vez confirmado el pago.
     * Continúa aunque fallen proveedores individuales (fallo parcial).
     *
     * @throws InvalidOrderStateException
     */
    public function procesarPagoPedido(Pedido $pedido): bool
    {
        return DB::transaction(function () use ($pedido) {
            if ($pedido->estatus !== 'pendiente_pago') {
                throw new InvalidOrderStateException($pedido->id, $pedido->estatus, 'pendiente_pago');
            }

            $cliente            = $pedido->cliente;
            $almacenPreferido   = $pedido->almacen_preferido;   // recuperado de Fase 1

            $detallesPendientes = $pedido->detalles()
                ->whereNull('pedido_proveedor_id')
                ->with('producto')  // producto es ProveedorProducto
                ->get()
                ->toArray();

            if (empty($detallesPendientes)) {
                Log::error('No hay productos pendientes para procesar', ['pedido_id' => $pedido->id]);
                return false;
            }

            $detallesPorProveedor = $this->agruparDetallesPorProveedorId($detallesPendientes);

            $totalProductos     = 0;
            $totalEnvio         = 0;
            $exitosos           = 0;
            $fallidos           = 0;
            $erroresDetallados  = [];

            foreach ($detallesPorProveedor as $proveedorId => $detalles) {
                $resultado = $this->procesarSubpedido(
                    $pedido,
                    $proveedorId,
                    $detalles,
                    $cliente,
                    $almacenPreferido,
                );

                if ($resultado !== null) {
                    $totalProductos += $resultado['subtotal'];
                    $totalEnvio     += $resultado['envio'];
                    $exitosos++;

                    Log::info('Subpedido procesado exitosamente', [
                        'pedido_id'    => $pedido->id,
                        'proveedor_id' => $proveedorId,
                        'folios'       => $resultado['folios'],
                        'total'        => $resultado['total'],
                    ]);
                } else {
                    $fallidos++;
                    $error = [
                        'proveedor_id'        => $proveedorId,
                        'productos_afectados' => collect($detalles)->pluck('clave_proveedor')->toArray(),
                        'timestamp'           => now()->toDateTimeString(),
                    ];
                    $erroresDetallados[] = $error;
                    $this->registrarSubpedidoFallido($pedido, $proveedorId, $detalles, $error);

                    Log::error('Subpedido falló — continuando con otros proveedores', [
                        'pedido_id'    => $pedido->id,
                        'proveedor_id' => $proveedorId,
                    ]);
                }
            }

            $estatusInfo = $this->determinarEstatusFinal($exitosos, $fallidos, $erroresDetallados);

            $pedido->update([
                'estatus'                  => $estatusInfo['estatus'],
                'errores_detallados'       => $erroresDetallados ? json_encode([
                    'mensaje' => $estatusInfo['mensaje'],
                    'detalles' => $erroresDetallados,
                ]) : null,
                'requiere_atencion_manual' => $fallidos > 0,
            ]);

            if ($fallidos > 0) {
                $this->notificarFallosParciales($pedido, $erroresDetallados);
            }

            return $estatusInfo['estatus'] === 'procesado';
        });
    }

    // =========================================================================
    // COTIZACIÓN RÁPIDA (sin crear pedido)
    // =========================================================================

    /**
     * Cotiza el envío de una lista de productos sin crear ningún pedido.
     *
     * El proveedor recibe productos con datos básicos (precio = 0) porque para
     * cotizar envío solo necesita peso/volumen/stock, no precio de venta.
     *
     * @param  array            $productos         [['clave' => 'XX', 'cantidad' => 2], ...]
     * @param  Cliente          $cliente
     * @param  string|int|null  $almacenPreferido
     *
     * @throws ShippingOutOfRangeException  Si el destino no tiene cobertura.
     * @throws ShippingQuoteException       Si todos los proveedores fallan.
     *
     * @return array [
     *   'cotizaciones'        => [['proveedor' => string, 'costo_envio' => float, 'detalles' => [...]], ...],
     *   'total_envio'         => float,
     *   'errores_proveedores' => [...],
     * ]
     */
    public function cotizarEnvioProductos(
        array           $productos,
        Cliente         $cliente,
        string|int|null $almacenPreferido = null,
    ): array {
        $productosNormalizados = $this->normalizarProductos($productos);
        $productosPorProveedor = $this->agruparProductosPorProveedor($productosNormalizados);

        $cotizaciones       = [];
        $totalEnvio         = 0;
        $erroresProveedores = [];

        foreach ($productosPorProveedor as $proveedorId => $productosDelProveedor) {
            try {
                $servicio = $this->proveedorFactory->crear($proveedorId);

                // El proveedor construye sus propios ProductoEnriquecidoData básicos para cotizar
                // (sin enriquecer precios — el proveedor sabe qué necesita para calcular flete)
                $productosParaCotizar = $servicio->prepararParaCotizacion($productosDelProveedor);

                $cotizacion = $servicio->cotizarEnvio($productosParaCotizar, $cliente, $almacenPreferido);

                $cotizaciones[] = [
                    'proveedor'   => $servicio->obtenerNombre(),
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
                throw $e;    // Sin cobertura = error fatal, no parcial

            } catch (ShippingQuoteException $e) {
                $erroresProveedores[] = ['proveedor_id' => $proveedorId, 'error' => $e->getMessage(), 'tipo' => 'cotizacion'];
                Log::warning('Fallo cotización de envío', ['proveedor_id' => $proveedorId, 'error' => $e->getMessage()]);

            } catch (\Exception $e) {
                $erroresProveedores[] = ['proveedor_id' => $proveedorId, 'error' => $e->getMessage(), 'tipo' => 'inesperado'];
                Log::error('Error inesperado al cotizar envío', ['proveedor_id' => $proveedorId, 'error' => $e->getMessage()]);
            }
        }

        if (empty($cotizaciones) && !empty($erroresProveedores)) {
            throw new ShippingQuoteException(
                'Todos los proveedores',
                'No se pudo cotizar envío con ningún proveedor disponible',
                $productos,
            );
        }

        return [
            'cotizaciones'        => $cotizaciones,
            'total_envio'         => round($totalEnvio, 2),
            'errores_proveedores' => $erroresProveedores,
        ];
    }

    // =========================================================================
    // PRIVADOS — routing
    // =========================================================================

    /**
     * Normaliza el input del cliente al formato interno estándar.
     * Input externo:  [['clave' => 'XX', 'cantidad' => 2], ...]
     * Output interno: [['codigo_proveedor' => 'XX', 'cantidad' => 2], ...]
     */
    private function normalizarProductos(array $productos): array
    {
        return collect($productos)->map(fn($p) => [
            'codigo_proveedor' => $p['clave'],
            'cantidad'         => (int) $p['cantidad'],
        ])->toArray();
    }

    /**
     * Agrupa productos normalizados por proveedor_id.
     * Solo hace routing: lee qué proveedor_id le corresponde a cada código.
     *
     * Input:  [['codigo_proveedor' => 'XX', 'cantidad' => 2], ...]
     * Output: [proveedorId => [['codigo_proveedor' => 'XX', 'cantidad' => 2], ...]]
     *
     * @throws ProductNotFoundException
     */
    private function agruparProductosPorProveedor(array $productos): array
    {
        $codigos = collect($productos)->pluck('codigo_proveedor')->unique()->toArray();

        $mapping = DB::table('proveedor_productos')
            ->whereIn('codigo_proveedor', $codigos)
            ->select('codigo_proveedor', 'proveedor_id')
            ->get()
            ->keyBy('codigo_proveedor');

        $agrupados = [];

        foreach ($productos as $producto) {
            $row = $mapping->get($producto['codigo_proveedor']);

            if (!$row) {
                throw new ProductNotFoundException($producto['codigo_proveedor']);
            }

            $agrupados[$row->proveedor_id][] = $producto;
        }

        return $agrupados;
    }

    /**
     * Agrupa detalles de DetallePedido ya cargados por proveedor_id.
     * Usa el eager-loaded 'producto' (ProveedorProducto) para obtener proveedor_id sin N+1.
     */
    private function agruparDetallesPorProveedorId(array $detalles): array
    {
        $agrupados = [];

        foreach ($detalles as $detalle) {
            // 'producto' es la relación a ProveedorProducto, cargada en procesarPagoPedido
            // ProveedorProducto contiene proveedor_id directamente
            $proveedorId = $detalle['producto']['proveedor_id'];
            $agrupados[$proveedorId][] = $detalle;
        }

        return $agrupados;
    }

    // =========================================================================
    // PRIVADOS — procesamiento de subpedidos
    // =========================================================================

    /**
     * Procesa un subpedido con un proveedor específico.
     * Retorna los totales del subpedido o null si falló.
     */
    private function procesarSubpedido(
        Pedido          $pedido,
        int             $proveedorId,
        array           $detalles,
        Cliente         $cliente,
        string|int|null $almacenPreferido,
    ): ?array {
        try {
            $servicio = $this->proveedorFactory->crear($proveedorId);

            $request = new PedidoProveedorRequestData(
                numeroOrden:       $pedido->folio,
                productos:         collect($detalles)->map(fn($d) => [
                    'proveedor_producto_id' => $d['proveedor_producto_id'],
                    'codigo_proveedor'      => $d['clave_proveedor'],
                    'cantidad'              => $d['cantidad'],
                    'precio_unitario'       => $d['precio_unitario'],
                ])->toArray(),
                datosEnvio:        $pedido->datos_envio,
                test:              config('app.env') !== 'production',
                almacenPreferido:  $almacenPreferido,
            );

            $response = $servicio->crearPedido($request, $cliente);

            if (!$response['success']) {
                Log::error('Proveedor rechazó el subpedido', [
                    'pedido_id'    => $pedido->id,
                    'proveedor_id' => $proveedorId,
                    'error'        => $response['error'] ?? 'Error desconocido',
                ]);
                return null;
            }

            // El proveedor puede devolver 1 o N órdenes (multi-almacén)
            $ordenes = isset($response['data'][0]) ? $response['data'] : [$response['data']];

            $resultado = ['subtotal' => 0, 'envio' => 0, 'total' => 0, 'folios' => [], 'pedidos_proveedor_ids' => []];

            foreach ($ordenes as $ordenData) {
                $pedidoProveedor = PedidoProveedor::create([
                    'pedido_id'                    => $pedido->id,
                    'proveedor_id'                 => $proveedorId,
                    'folio_pedido'                 => $ordenData['folio_pedido'] ?? null,
                    'moneda_cobro_productos'       => $ordenData['moneda_cobro_productos'] ?? 'USD',
                    'precio_total_productos'       => $ordenData['precio_total_productos'] ?? 0,
                    'moneda_cobro_envio'           => $ordenData['moneda_cobro_envio'] ?? 'MXN',
                    'precio_total_envio'           => $ordenData['precio_total_envio'] ?? 0,
                    'iva_incluido'                 => $ordenData['iva_incluido'] ?? false,
                    'envio_gratis'                 => $ordenData['envio_gratis'] ?? false,
                    'fecha_entrega_estimada'       => $ordenData['fecha_entrega_estimada'] ?? null,
                    'status'                       => $ordenData['status'] ?? 'en_proceso',
                    'tipo_cambio_aplicado'         => $ordenData['tipo_cambio_aplicado'] ?? null,
                    'precio_total_productos_mxn'   => $ordenData['precio_total_productos_mxn'] ?? 0,
                    'precio_total_envio_mxn'       => $ordenData['precio_total_envio_mxn'] ?? 0,
                    'precio_total_mxn'             => $ordenData['precio_total_mxn'] ?? 0,
                    'email_agente'                 => $ordenData['email_agente'] ?? $ordenData['emailAgente'] ?? null,
                    'email_almacen'                => $ordenData['email_almacen'] ?? $ordenData['emailAlmacen'] ?? null,
                    'origen_envio'                 => $ordenData['origen_envio'] ?? $ordenData['origen'] ?? null,
                ]);

                $resultado['pedidos_proveedor_ids'][] = $pedidoProveedor->id;
                $resultado['folios'][]                 = $ordenData['folio_pedido'];
                $resultado['subtotal']                += $ordenData['precio_total_productos_mxn']                 ?? 0;
                $resultado['envio']                   += $ordenData['precio_total_envio_mxn']     ?? 0;
                $resultado['total']                   += $ordenData['precio_total_mxn']                    ?? 0;
            }

            // Vincular detalles: si hay múltiples órdenes, cada detalle va a su PedidoProveedor
            // por ahora todos al primero; extender si el proveedor devuelve mapping detalle→orden
            $idsDetalles = collect($detalles)->pluck('id')->toArray();
            DetallePedido::whereIn('id', $idsDetalles)
                ->update(['pedido_proveedor_id' => $resultado['pedidos_proveedor_ids'][0]]);

            return $resultado;

        } catch (\Exception $e) {
            Log::error('Excepción al procesar subpedido', [
                'pedido_id'    => $pedido->id,
                'proveedor_id' => $proveedorId,
                'exception'    => $e->getMessage(),
                'trace'        => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    // =========================================================================
    // PRIVADOS — persistencia
    // =========================================================================

    private function guardarDetallesPendientes(Pedido $pedido, array $productos): void
    {
        $filas = collect($productos)->map(fn($p) => [
            'pedido_id'             => $pedido->id,
            'pedido_proveedor_id'   => null,
            'proveedor_producto_id' => $p->proveedorProductoId,
            'clave_proveedor'       => $p->codigoProveedor,
            'cantidad'              => $p->cantidad,
            'precio_unitario'       => $p->precioUnitario,
            'subtotal'              => $p->getSubtotal(),
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        DetallePedido::insert($filas->toArray());
    }

    private function registrarSubpedidoFallido(Pedido $pedido, int $proveedorId, array $detalles, array $error): void
    {
        try {
            PedidoProveedor::create([
                'pedido_id'                => $pedido->id,
                'proveedor_id'             => $proveedorId,
                'folio_pedido'             => $pedido->folio,
                'precio_total_productos'   => $detalle->subtotal ?? 0,
                'precio_total_envio'       => 0,
                'precio_total'             => $detalle->subtotal ?? 0,
                'moneda'                   => 'MXN',
                'status'                   => 'fallido',
                'error_mensaje'            => 'Error al procesar con el proveedor',
                'error_detalle'            => json_encode([
                    'detalles'             => $detalles,
                    'error'                => $error,
                    'requiere_reembolso'   => true,
                ]),
                'requiere_atencion_manual' => true,
            ]);
        } catch (\Exception $e) {
            Log::error('Error al registrar subpedido fallido', [
                'pedido_id'    => $pedido->id,
                'proveedor_id' => $proveedorId,
                'error'        => $e->getMessage(),
            ]);
        }
    }

    // =========================================================================
    // PRIVADOS — helpers
    // =========================================================================

    private function resolverCliente(): \App\Models\Cliente
    {
        $cliente = Auth::user()?->cliente;

        if (!$cliente) {
            throw new ClientProfileNotFoundException(Auth::id());
        }

        return $cliente;
    }

    private function determinarEstatusFinal(int $exitosos, int $fallidos, array $errores): array
    {
        if ($exitosos > 0 && $fallidos === 0) {
            return ['estatus' => 'procesado', 'mensaje' => 'Pedido procesado exitosamente'];
        }

        if ($exitosos > 0 && $fallidos > 0) {
            return [
                'estatus' => 'procesado_parcial',
                'mensaje' => "{$exitosos} proveedor(es) exitoso(s), {$fallidos} fallido(s). REQUIERE REEMBOLSO de productos no entregados.",
            ];
        }

        return [
            'estatus' => 'fallido',
            'mensaje' => 'Pedido falló completamente. Todos los proveedores reportaron errores. REQUIERE REEMBOLSO TOTAL.',
        ];
    }

    private function notificarFallosParciales(Pedido $pedido, array $errores): void
    {
        Log::critical('ATENCIÓN REQUERIDA: Pedido con fallos parciales', [
            'pedido_id'       => $pedido->id,
            'folio'           => $pedido->folio,
            'cliente_id'      => $pedido->cliente_id,
            'total_cobrado'   => $pedido->precio_total,
            'errores'         => $errores,
            'accion_requerida' => 'Revisar y procesar reembolso de productos no entregados',
        ]);
    }

    private function generarFolio(): string
    {
        return 'NXTPED-' . now()->format('Ymd') . '-' . rand(1000, 9999);
    }
}