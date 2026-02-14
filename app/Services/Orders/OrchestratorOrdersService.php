<?php

namespace App\Services\Orders;

use App\Data\Pedidos\PedidoProveedorRequestData;
use App\Data\Pedidos\ProductoPedidoData;
use App\Data\Pedidos\ProductoEnriquecidoData;
use App\Exceptions\Cva\CvaStockException;
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
use App\Models\ProveedorProducto;
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
     * @param array $productos ['productos' => [['clave' => 'XX', 'cantidad' => 2]], 'datos_envio' => [...]]
     * @throws ClientProfileNotFoundException
     * @throws ProductNotFoundException
     * @throws ShippingOutOfRangeException
     * @throws ShippingQuoteException
     */
    public function crearPedido(array $productos): Pedido
    {
        return DB::transaction(function () use ($productos) {
            $user = Auth::user();
            $cliente = $user->cliente;

            // 1. Validar que el cliente exista
            if (!$cliente) {
                throw new ClientProfileNotFoundException(Auth::id());
            }
           
            // 2. Enriquecer con datos de la BD (puede lanzar ProductNotFoundException)
            $productosEnriquecidos = $this->enriquecerProductos($productos);
            //VALIDAR STOCK
            $productosValidacion = collect($productosEnriquecidos)->map(function ($productoDTO) {
                return [
                    'codigo_proveedor' => $productoDTO->codigoProveedor, // ← Nota: camelCase
                    'cantidad' => $productoDTO->cantidad,
                    'stock' => $productoDTO->stock ?? 0,
                    'stock_cd' => $productoDTO->stockCd ?? 0,
                ];
            })->toArray();

             $this->validarDisponibilidad($productosValidacion);


            // 3. Calcular totales estimados
            // IMPORTANTE: Esta validación ahora DETIENE la creación si no hay alcance
            $totales = $this->calcularTotalesEstimados($productosEnriquecidos, $cliente);

            // 4. Crear pedido maestro (solo si pasó todas las validaciones)
            $pedido = Pedido::create([
                'folio' => $this->generarFolio(),
                'fecha_pedido' => now(),
                'estatus' => 'pendiente_pago',
                'cliente_id' => $cliente->id,
                'precio_total' => $totales['total'],
                'precio_total_productos' => $totales['productos'],
                'precio_total_envio' => $totales['envio'],
                'datos_envio' => $productos['datos_envio'] ?? null,
            ]);

            // 5. Guardar productos pendientes
            $this->guardarProductosPendientes($pedido, $productosEnriquecidos);

            return $pedido->fresh(['detalles']);
        });
    }

    /**
     * FASE 2: Procesar subpedidos con proveedores después del pago
     * Se ejecuta cuando el pago se ha confirmado
     * 
     * - Los subpedidos exitosos se guardan normalmente
     * - Los fallidos se registran con estatus 'fallido' para seguimiento
     * - El pedido maestro queda con estatus que indica necesidad de atención manual
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
                ->get()->toArray();

            if (empty($detallesPendientes)) {
                \Log::error('No hay productos pendientes para procesar', [
                    'pedido_id' => $pedido->id
                ]);
                return false;
            }

            // Agrupar detalles por proveedor
            $productosPorProveedor = $this->agruparDetallesPorProveedor($detallesPendientes);

            $totalGeneral = 0;
            $totalProductos = 0;
            $totalEnvio = 0;
            $subpedidosExitosos = 0;
            $subpedidosFallidos = 0;
            $erroresDetallados = [];

            // Procesar cada subpedido - CONTINUAR AUNQUE FALLEN ALGUNOS
            foreach ($productosPorProveedor as $proveedorId => $productos) {
                $resultado = $this->procesarSubpedido(
                    $pedido,
                    $proveedorId,
                    $productos,
                    $cliente,
                    $pedido->datos_envio
                );

                if ($resultado) {
                    // ÉXITO: Acumular totales
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
                    // FALLO: Registrar error pero continuar con otros proveedores
                    $subpedidosFallidos++;
                    
                    $error = [
                        'proveedor_id' => $proveedorId,
                        'productos_afectados' => collect($productos)->pluck('clave')->toArray(),
                        'timestamp' => now()->toDateTimeString()
                    ];
                    
                    $erroresDetallados[] = $error;
                    
                    // Registrar subpedido fallido para seguimiento
                    $this->registrarSubpedidoFallido($pedido, $proveedorId, $productos, $error);
                    
                    \Log::error('Subpedido falló - continuando con otros proveedores', [
                        'pedido_id' => $pedido->id,
                        'proveedor_id' => $proveedorId,
                        'productos_afectados' => $error['productos_afectados']
                    ]);
                }
            }

            // Determinar estatus final según resultados
            $estatusInfo = $this->determinarEstatusFinal(
                $subpedidosExitosos,
                $subpedidosFallidos,
                $erroresDetallados
            );

            // Actualizar pedido maestro con totales reales y estatus
            $pedido->update([
                'estatus' => $estatusInfo['estatus'],
                'error_mensaje' => $estatusInfo['mensaje'],
                'errores_detallados' => $erroresDetallados ? json_encode($erroresDetallados) : null,
                'requiere_atencion_manual' => $subpedidosFallidos > 0,
            ]);

            // Si hubo fallos, notificar al equipo de soporte
            if ($subpedidosFallidos > 0) {
                $this->notificarFallosParciales($pedido, $erroresDetallados);
            }

            // Retornar true solo si TODO fue exitoso
            return $estatusInfo['estatus'] === 'procesado';
        });
    }

    /**
     * Registrar subpedido fallido para seguimiento manual
     */
    private function registrarSubpedidoFallido(
        Pedido $pedido,
        int $proveedorId,
        array $productos,
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
                    'productos' => $productos,
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

    /**
     * Notificar al equipo de soporte sobre fallos parciales
     */
    private function notificarFallosParciales(Pedido $pedido, array $errores): void
    {
        try {
            // Aquí puedes enviar email, Slack, crear ticket, etc.
            \Log::critical('ATENCIÓN REQUERIDA: Pedido con fallos parciales', [
                'pedido_id' => $pedido->id,
                'folio' => $pedido->folio,
                'cliente_id' => $pedido->cliente_id,
                'total_cobrado' => $pedido->precio_total,
                'errores' => $errores,
                'accion_requerida' => 'Revisar y procesar reembolso de productos no entregados'
            ]);

            // Ejemplo: Enviar notificación
            // event(new PedidoRequiereAtencion($pedido, $errores));
            
        } catch (\Exception $e) {
            \Log::error('Error al notificar fallos parciales', [
                'pedido_id' => $pedido->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Enriquecer productos simples (clave + cantidad) con datos de la BD
     * 
     * @throws ProductNotFoundException
     */
    private function enriquecerProductos(array $productosSimples): array
    {
        $claves = collect($productosSimples)->pluck('clave')->unique()->toArray();

        $productosDb = ProveedorProducto::whereIn('codigo_proveedor', $claves)
            ->select('id','proveedor_id','producto_id','codigo_proveedor','stock','stock_cd')
            ->with('pricio')
            ->get()
            ->keyBy('codigo_proveedor');

        return collect($productosSimples)->map(function ($productoSimple) use ($productosDb) {
            $proveedorProducto = $productosDb->get($productoSimple['clave']);
            
            if (!$proveedorProducto) {
                throw new ProductNotFoundException($productoSimple['clave']);
            }

            return ProductoEnriquecidoData::fromProveedorProducto(
                $proveedorProducto,
                $productoSimple['cantidad']
            );
        })->toArray();
    }

    /**
     * Validar disponibilidad de stock para todos los productos
     * 
     * AHORA AJUSTADO para recibir el formato de cotizarEnvio
     */
    public function validarDisponibilidad(array $productos): bool
    {
        foreach ($productos as $producto) {
            // Manejar ambos formatos: array asociativo y objeto
            $codigoProveedor = $producto['codigo_proveedor'] ?? null;
            $cantidadSolicitada = $producto['cantidad_a_cotizar'] ?? $producto['cantidad'] ?? 0;
            $stockCedis = $producto['stock_cd'] ?? 0;
            $stockSucursal = $producto['stock'] ?? 0;

            if (!$codigoProveedor) {
                throw new CvaStockException(
                    "Producto sin código de proveedor",
                    400
                );
            }

            $stockTotal = $stockCedis + $stockSucursal;

            // Verificar stock total
            if ($stockTotal < $cantidadSolicitada) {
                throw new CvaStockException(
                    "Stock insuficiente para {$codigoProveedor}. " .
                    "Solicitado: {$cantidadSolicitada}, " .
                    "Disponible: {$stockTotal} (CEDIS: {$stockCedis}, Sucursal: {$stockSucursal})",
                    400
                );
            }
        }

        return true;
    }

     /**
     * Cotizar envío de productos sin crear pedido
     * 
     * @throws ShippingException
     */
    public function cotizarEnvioProductos(array $productos, Cliente $cliente): array
    {
        $productosRequest = collect($productos)->keyBy('clave');
        $productosClaves = $productosRequest->keys()->toArray();
        
        $productosCompletos = ProveedorProducto::whereIn('codigo_proveedor', $productosClaves)
            ->select('id', 'proveedor_id', 'producto_id', 'stock', 'stock_cd', 'codigo_proveedor')
            ->get()
            ->map(function ($item) use ($productosRequest) {
                $item->cantidad_a_cotizar = $productosRequest[$item->codigo_proveedor]['cantidad'] ?? 0;
                return $item;
            })
            ->toArray();

        $productosPorProveedor = $this->agruparPorProveedor($productosCompletos);

        $cotizaciones = [];
        $totalEnvio = 0;
        $erroresProveedores = [];

        foreach ($productosPorProveedor as $proveedorId => $productosProveedor) {
            try {
                $proveedorService = $this->proveedorFactory->crear($proveedorId);
                
                if (!method_exists($proveedorService, 'cotizarEnvio')) {
                    \Log::warning('Proveedor sin método cotizarEnvio', [
                        'proveedor_id' => $proveedorId
                    ]);
                    continue;
                }

                // AHORA cotizarEnvio LANZA EXCEPCIONES en lugar de retornar arrays con error
                $cotizacion = $proveedorService->cotizarEnvio($productosProveedor, $cliente);
                
                $cotizaciones[] = [
                    'proveedor_id' => $proveedorId,
                    'costo_envio' => $cotizacion['monto_total'] ?? 0,
                    'detalles' => $cotizacion
                ];

                $totalEnvio += $cotizacion['monto_total'] ?? 0;

            } catch (ShippingOutOfRangeException $e) {
                // Error crítico: Cliente fuera de alcance
                // Detener todo el proceso y propagar la excepción
                throw $e;
                
            } catch (ShippingQuoteException $e) {
                // Error al cotizar con un proveedor específico
                // Guardar para reporte pero continuar con otros proveedores
                $erroresProveedores[] = [
                    'proveedor_id' => $proveedorId,
                    'error' => $e->getMessage(),
                    'context' => $e->getContext()
                ];
                
                \Log::warning('Fallo cotización con proveedor - continuando con otros', [
                    'proveedor_id' => $proveedorId,
                    'error' => $e->getMessage()
                ]);
                
            } catch (\Exception $e) {
                // Error inesperado
                $erroresProveedores[] = [
                    'proveedor_id' => $proveedorId,
                    'error' => $e->getMessage(),
                    'tipo' => 'inesperado'
                ];
                
                \Log::error('Error inesperado al cotizar envío', [
                    'proveedor_id' => $proveedorId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }

        // Si TODOS los proveedores fallaron, lanzar excepción
        if (empty($cotizaciones) && !empty($erroresProveedores)) {
            throw new ShippingQuoteException(
                'Todos los proveedores',
                'No se pudo cotizar envío con ningún proveedor disponible',
                $productos
            );
        }

        return [
            'cotizaciones' => $cotizaciones,
            'total_envio' => $totalEnvio,
            'errores_proveedores' => $erroresProveedores // Info para logging
        ];
    }

    /**
     * Procesar un subpedido con un proveedor específico
     */
    private function procesarSubpedido(
        Pedido $pedido,
        int $proveedorId,
        array $productos,
        Cliente $cliente,
        ?array $datosEnvio
    ): ?array {
        try {
            $proveedorService = $this->proveedorFactory->crear($proveedorId);

            // Preparar request
            $request = new PedidoProveedorRequestData(
                proveedorId: $proveedorId,
                numeroOrden: $pedido->folio,
                productos: $productos,
                datosEnvio: $datosEnvio,
                test: config('app.env') !== 'production'
            );

            // Llamar al proveedor
            $response = $proveedorService->crearPedido($request, $cliente);

            if (!$response['success']) {
                \Log::error('Error al procesar subpedido', [
                    'pedido_id' => $pedido->id,
                    'proveedor_id' => $proveedorId,
                    'error' => $response['error'] ?? 'Error desconocido'
                ]);
                return null;
            }

            // Manejar múltiples pedidos (caso CVA con CEDIS + Sucursal)
            $result = [
                'subtotal' => 0,
                'envio' => 0,
                'total' => 0,
                'pedidos_proveedor_ids' => [],
                'folios' => []
            ];

            // $response['data'] puede ser array de pedidos o pedido único
            $pedidosData = $response['data'];
            
            // Normalizar a array si es un solo pedido
            if (!isset($pedidosData[0])) {
                $pedidosData = [$pedidosData];
            }

            foreach ($pedidosData as $pedidoData) {
                // Crear registro de subpedido EXITOSO
                $pedidoProveedor = PedidoProveedor::create([
                    'pedido_id' => $pedido->id,
                    'proveedor_id' => $proveedorId,
                    'folio_pedido' => $pedidoData['folioPedido'],
                    'precio_total_productos' => $pedidoData['subtotal'],
                    'precio_total_envio' => $pedidoData['flete']['monto_total'] ?? 0,
                    'precio_total' => $pedidoData['total'],
                    'moneda' => $pedidoData['moneda'] ?? 'MXN',
                    'email_agente' => $pedidoData['emailAgente'] ?? null,
                    'email_almacen' => $pedidoData['emailAlmacen'] ?? null,
                    'envio_gratis' => ($pedidoData['flete']['monto_total'] ?? 0) == 0,
                    'origen_envio' => $pedidoData['origen'] ?? null,
                    'status' => 'creado',
                    'requiere_atencion_manual' => false
                ]);

                $result['pedidos_proveedor_ids'][] = $pedidoProveedor->id;
                $result['folios'][] = $pedidoData['folioPedido'];
                $result['subtotal'] += $pedidoData['subtotal'] ?? 0;
                $result['envio'] += $pedidoData['flete']['monto_total'] ?? 0;
                $result['total'] += $pedidoData['total'] ?? 0;
            }

            // Actualizar detalles con el primer pedido_proveedor_id
            DetallePedido::where('pedido_id', $pedido->id)
                ->whereIn('proveedor_producto_id', collect($productos)->pluck('proveedor_producto_id'))
                ->update([
                    'pedido_proveedor_id' => $result['pedidos_proveedor_ids'][0]
                ]);

            return $result;

        } catch (\Exception $e) {
            \Log::error('Excepción al procesar subpedido', [
                'pedido_id' => $pedido->id,
                'proveedor_id' => $proveedorId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Guardar productos pendientes en DetallePedido
     * 
     */
    private function guardarProductosPendientes(Pedido $pedido, array $productos): void
    {
        $detalles = collect($productos)->map(function ($producto) use ($pedido) {
            return [
                'pedido_id' => $pedido->id,
                'pedido_proveedor_id' => null, // Se asignará después del pago
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

     /**
     * Calcular totales estimados
     * 
     * @throws ShippingOutOfRangeException
     * @throws ShippingQuoteException
     */
    private function calcularTotalesEstimados(array $productos, Cliente $cliente): array
    {
        $productosBasicos = collect($productos)
            ->map(fn($value) => [
                'clave' => $value->codigoProveedor,
                'cantidad' => $value->cantidad
            ])
            ->toArray();

        // 1. Calcular total de productos
        $totalProductos = collect($productos)->sum(fn($p) => $p->getSubtotal());

        // 2. Cotizar envíos por proveedor - AHORA LANZA EXCEPCIONES
        $resultadoCotizacion = $this->cotizarEnvioProductos($productosBasicos, $cliente);

        return [
            'productos' => $totalProductos,
            'envio' => $resultadoCotizacion['total_envio'], 
            'total' => $totalProductos + $resultadoCotizacion['total_envio']
        ];
    }

    /**
     * Agrupar productos enriquecidos por proveedor
     * 
     */
    private function agruparPorProveedor(array $productos): array
    {
        $agrupados = [];
        
        foreach ($productos as $producto) {
            $proveedorId = $producto['proveedor_id'] ?? $producto['proveedorId'];
            $agrupados[$proveedorId][] = $producto;
        }
        
        return $agrupados;
    }

    /**
     * Agrupar detalles de pedido por proveedor
     */
    private function agruparDetallesPorProveedor($detalles): array
    {
        $agrupados = [];
        
        foreach ($detalles as $detalle) {
            $proveedorProducto = ProveedorProducto::find($detalle['proveedor_producto_id'])->toArray();
            
            if (!$proveedorProducto) {
                \Log::error('ProveedorProducto no encontrado', [
                    'detalle_id' => $detalle['id'],
                    'proveedor_producto_id' => $detalle['proveedor_producto_id']
                ]);
                continue;
            }

            $proveedorId = $proveedorProducto['proveedor_id'];

            $agrupados[$proveedorId][] = [
                'proveedor_producto_id' => $detalle['proveedor_producto_id'],
                'proveedor_id' => $proveedorId,
                'producto_id' => $proveedorProducto['producto_id'],
                'clave' => $detalle['clave_proveedor'],
                'codigo_proveedor' => $detalle['clave_proveedor'],
                'cantidad' => $detalle['cantidad'],
                'precio_unitario' => $detalle['precio_unitario'],
                'stock' => $proveedorProducto['stock'] ?? 0,
                'stock_cd' => $proveedorProducto['stock_cd'] ?? 0
            ];
        }

        return $agrupados;
    }

    /**
     * Determinar estatus final del pedido con información detallada
     */
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

    /**
     * Generar folio único
     */
    private function generarFolio(): string
    {
        return 'NXTPED-' . now()->format('Ymd') . '-' . rand(1000, 9999);
    }
}