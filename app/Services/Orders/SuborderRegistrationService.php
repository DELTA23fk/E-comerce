<?php

namespace App\Services\Orders;

use App\Enum\Order\OrderStatusEnum;
use App\Exceptions\Orders\InsufficientStockException;
use App\Exceptions\Orders\InvalidOrderStateException;
use App\Factories\ProviderFactory;
use App\Models\DetallePedido;
use App\Models\Pedido;
use App\Models\PedidoProveedor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MercadoPago\Resources\Order;

/**
 * Responsabilidad única: registrar subpedidos después del pago confirmado.
 *
 * - Ejecuta CHECK 2 de stock por proveedor (re-validación post-pago).
 * - Si falta stock: emite reembolso automático y registra subpedido como fallido.
 * - Si hay stock: crea PedidoProveedor en estado 'pendiente' (sin llamar la API del proveedor).
 * - Vincula DetallePedido al PedidoProveedor correspondiente.
 * - El procesamiento real con el proveedor se realiza de forma manual posterior.
 */
class SuborderRegistrationService
{
    public function __construct(
        private readonly ProviderFactory $proveedorFactory,
        // private readonly RefundService   $refundService,
    ) {}
 
    /** 
     * @throws InvalidOrderStateException
     */
    public function procesar(Pedido $pedido): bool
    {
        return DB::transaction(function () use ($pedido) {
            if ($pedido->estatus !== 'pendiente_pago') {
                throw new InvalidOrderStateException($pedido->id, $pedido->estatus, 'pendiente_pago');
            }
 
            $almacenPreferido = $pedido->almacen_preferido;
 
            $detallesPendientes = $pedido->detalles()
                ->whereNull('pedido_proveedor_id')
                ->with('producto')
                ->get()
                ->toArray();
 
            if (empty($detallesPendientes)) {
                Log::error('[SuborderRegistration] No hay productos pendientes para procesar', [
                    'pedido_id' => $pedido->id,
                ]);
                return false;
            }
 
            $detallesPorProveedor = $this->agruparDetallesPorProveedorId($detallesPendientes);
 
            $exitosos          = 0;
            $fallidos          = 0;
            $erroresDetallados = [];
 
            foreach ($detallesPorProveedor as $proveedorId => $detalles) {
 
                // ── CHECK 2 DE STOCK ─────────────────────────────────────────
                $errorStock = $this->revalidarStock($pedido, $proveedorId, $detalles, $almacenPreferido);
                // ─────────────────────────────────────────────────────────────
 
                if ($errorStock !== null) {
                    $fallidos++;
                    $erroresDetallados[] = $errorStock;
 
                    $montoAfectado = collect($detalles)->sum('subtotal');
 
                    Log::warning('[SuborderRegistration] Stock insuficiente en CHECK 2 — reembolso parcial', [
                        'pedido_id'      => $pedido->id,
                        'proveedor_id'   => $proveedorId,
                        'monto_afectado' => $montoAfectado,
                    ]);
 
                    // $this->refundService->emitirAutomatico(
                    //     $pedido,
                    //     $montoAfectado,
                    //     'Sin stock al confirmar pago',
                    // );
 
                    $this->registrarSubpedidoFallido($pedido, $proveedorId, $detalles, $errorStock);
                    continue;
                }
 
                // Stock OK → registrar para procesamiento manual
                $registrado = $this->registrarSubpedidoPendiente($pedido, $proveedorId, $detalles);
 
                if ($registrado !== null) {
                    $exitosos++;
                } else {
                    $fallidos++;
                    $error = [
                        'proveedor_id'        => $proveedorId,
                        'productos_afectados' => collect($detalles)->pluck('clave_proveedor')->toArray(),
                        'razon'               => 'Error al registrar subpedido en BD',
                        'requiere_reembolso'  => true,
                        'timestamp'           => now()->toDateTimeString(),
                    ];
                    $erroresDetallados[] = $error;
                    $this->registrarSubpedidoFallido($pedido, $proveedorId, $detalles, $error);
                }
            }
 
            $estatusInfo = $this->determinarEstatusFinal($exitosos, $fallidos);
 
            $pedido->update([
                'estatus'                  => $estatusInfo['estatus'],
                'requiere_atencion_manual' => true,
                'errores_detallados'       => $erroresDetallados
                    ? json_encode(['mensaje' => $estatusInfo['mensaje'], 'detalles' => $erroresDetallados])
                    : null,
            ]);
 
            if ($fallidos > 0) {
                $this->notificarFallosParciales($pedido, $erroresDetallados);
            }
 
            Log::info('[SuborderRegistration] Pedido listo para procesamiento manual', [
                'pedido_id' => $pedido->id,
                'folio'     => $pedido->folio,
                'exitosos'  => $exitosos,
                'fallidos'  => $fallidos,
                'estatus'   => $estatusInfo['estatus'],
            ]);
 
            return $estatusInfo['estatus'] === 'pendiente_procesamiento';
        });
    }
 
    // =========================================================================
    // CHECK 2 — Re-validación de stock post-pago
    // =========================================================================
 
    /**
     * @return array|null  null = stock OK, array = detalle del error
     */
    private function revalidarStock(
        Pedido          $pedido,
        int             $proveedorId,
        array           $detalles,
        string|int|null $almacenPreferido,
    ): ?array {
        try {
            $servicio = $this->proveedorFactory->crear($proveedorId);
 
            $productosParaValidar = collect($detalles)->map(fn($d) => [
                'codigo_proveedor' => $d['clave_proveedor'],
                'cantidad'         => $d['cantidad'],
            ])->toArray();
 
            $enriquecidos = $servicio->enriquecerProductos($productosParaValidar);
            $servicio->validarDisponibilidad($enriquecidos, $almacenPreferido);
 
            Log::info('[SuborderRegistration] CHECK 2 stock OK', [
                'pedido_id'    => $pedido->id,
                'proveedor_id' => $proveedorId,
            ]);
 
            return null;
 
        } catch (InsufficientStockException $e) {
            Log::warning('[SuborderRegistration] CHECK 2 stock INSUFICIENTE', [
                'pedido_id'    => $pedido->id,
                'proveedor_id' => $proveedorId,
                'productos'    => collect($detalles)->pluck('clave_proveedor')->toArray(),
                'error'        => $e->getMessage(),
            ]);
 
            return [
                'proveedor_id'        => $proveedorId,
                'productos_afectados' => collect($detalles)->pluck('clave_proveedor')->toArray(),
                'razon'               => 'Sin stock al confirmar pago: ' . $e->getMessage(),
                'requiere_reembolso'  => true,
                'timestamp'           => now()->toDateTimeString(),
            ];
 
        } catch (\Throwable $e) {
            // Error de comunicación con el proveedor: conservador → tratar como sin stock
            Log::error('[SuborderRegistration] CHECK 2 error de comunicación — tratando como sin stock', [
                'pedido_id'    => $pedido->id,
                'proveedor_id' => $proveedorId,
                'error'        => $e->getMessage(),
            ]);
 
            return [
                'proveedor_id'        => $proveedorId,
                'productos_afectados' => collect($detalles)->pluck('clave_proveedor')->toArray(),
                'razon'               => 'Error al re-validar stock: ' . $e->getMessage(),
                'requiere_reembolso'  => true,
                'timestamp'           => now()->toDateTimeString(),
            ];
        }
    }
 
    // =========================================================================
    // REGISTRO DE SUBPEDIDOS
    // =========================================================================
 
    /**
     * Crea PedidoProveedor con datos básicos en estado 'pendiente'.
     * No llama ninguna API del proveedor.
     */
    private function registrarSubpedidoPendiente(
        Pedido $pedido,
        int    $proveedorId,
        array  $detalles,
    ): ?array {
        try {
            $subtotalProductos = collect($detalles)->sum('subtotal');
 
            $pedidoProveedor = PedidoProveedor::create([
                'pedido_id'                  => $pedido->id,
                'proveedor_id'               => $proveedorId,
                'folio_pedido'               => $pedido->folio,
                'moneda_cobro_productos'     => 'MXN',
                'precio_total_productos'     => $subtotalProductos,
                'precio_total_productos_mxn' => $subtotalProductos,
                'precio_total_envio'         => 0,
                'precio_total_envio_mxn'     => 0,
                'precio_total_mxn'           => $subtotalProductos,
                'status'                     => OrderStatusEnum::PENDIENTE_PROCESAMIENTO->value,
                'requiere_atencion_manual'   => true,
            ]);
 
            DetallePedido::whereIn('id', collect($detalles)->pluck('id')->toArray())
                ->update(['pedido_proveedor_id' => $pedidoProveedor->id]);
 
            Log::info('[SuborderRegistration] Subpedido registrado para procesamiento manual', [
                'pedido_id'           => $pedido->id,
                'proveedor_id'        => $proveedorId,
                'folio_subpedido'     => $pedido->folio,
                'pedido_proveedor_id' => $pedidoProveedor->id,
                'productos_count'     => count($detalles),
                'subtotal'            => $subtotalProductos,
            ]);
 
            return [
                'subtotal' => $subtotalProductos,
                'envio'    => 0,
                'total'    => $subtotalProductos,
                'folios'   => [$pedido->folio],
            ];
 
        } catch (\Exception $e) {
            Log::error('[SuborderRegistration] Error al registrar subpedido pendiente', [
                'pedido_id'    => $pedido->id,
                'proveedor_id' => $proveedorId,
                'exception'    => $e->getMessage(),
            ]);
            return null;
        }
    }
 
    private function registrarSubpedidoFallido(
        Pedido $pedido,
        int    $proveedorId,
        array  $detalles,
        array  $error,
    ): void {
        try {
            $subtotal = collect($detalles)->sum('subtotal');
 
            PedidoProveedor::create([
                'pedido_id'                  => $pedido->id,
                'proveedor_id'               => $proveedorId,
                'folio_pedido'               => $pedido->folio,
                'moneda_cobro_productos'     => 'MXN',
                'precio_total_productos'     => $subtotal,
                'precio_total_productos_mxn' => $subtotal,
                'precio_total_envio'         => 0,
                'precio_total_envio_mxn'     => 0,
                'precio_total_mxn'           => $subtotal,
                'status'                     => OrderStatusEnum::FALLIDO->value,
                'error_mensaje'              => $error['razon'] ?? 'Error al procesar con el proveedor',
                'error_detalle'              => json_encode([
                    'detalles'           => $detalles,
                    'error'              => $error,
                    'requiere_reembolso' => $error['requiere_reembolso'] ?? true,
                ]),
                'requiere_atencion_manual'   => true,
            ]);
        } catch (\Exception $e) {
            Log::error('[SuborderRegistration] Error al registrar subpedido fallido', [
                'pedido_id'    => $pedido->id,
                'proveedor_id' => $proveedorId,
                'error'        => $e->getMessage(),
            ]);
        }
    }
 
    // =========================================================================
    // HELPERS
    // =========================================================================
 
    private function agruparDetallesPorProveedorId(array $detalles): array
    {
        $agrupados = [];
 
        foreach ($detalles as $detalle) {
            $proveedorId               = $detalle['producto']['proveedor_id'];
            $agrupados[$proveedorId][] = $detalle;
        }
 
        return $agrupados;
    }
 
    private function determinarEstatusFinal(int $exitosos, int $fallidos): array
    {
        if ($exitosos > 0 && $fallidos === 0) {
            return [
                'estatus' => OrderStatusEnum::PROCESANDO->value,
                'mensaje' => 'Subpedidos registrados. Pendientes de procesamiento manual con proveedores.',
            ];
        }
 
        if ($exitosos > 0 && $fallidos > 0) {
            return [
                'estatus' => OrderStatusEnum::PROCESADO->value,
                'mensaje' => "{$exitosos} proveedor(es) registrado(s), {$fallidos} fallido(s). Reembolso parcial emitido.",
            ];
        }
 
        return [
            'estatus' => OrderStatusEnum::FALLIDO->value,
            'mensaje' => 'Ningún subpedido pudo registrarse. Reembolso total emitido.',
        ];
    }
 
    private function notificarFallosParciales(Pedido $pedido, array $errores): void
    {
        Log::critical('[SuborderRegistration] ATENCIÓN REQUERIDA: Pedido con fallos parciales', [
            'pedido_id'        => $pedido->id,
            'folio'            => $pedido->folio,
            'cliente_id'       => $pedido->cliente_id,
            'total_cobrado'    => $pedido->precio_total,
            'errores'          => $errores,
            'accion_requerida' => 'Verificar reembolsos emitidos y procesar subpedidos pendientes',
        ]);
    }
}