<?php

namespace App\Services\Orders;

use App\Data\Payment\PreferenceResponseData;
use App\Data\Payment\WebhookResultData;
use App\Data\Pedidos\PedidoData;
use App\Data\Pedidos\PedidoProveedorRequestData;
use App\Exceptions\InvalidPaymentStateException;
use App\Exceptions\Orders\ClientProfileNotFoundException;
use App\Exceptions\Orders\InvalidOrderStateException;
use App\Exceptions\Orders\ProductNotFoundException;
use App\Exceptions\Orders\ShippingOutOfRangeException;
use App\Exceptions\Orders\ShippingQuoteException;
use App\Exceptions\PaymentGatewayException;
use App\Factories\PaymentGatewayFactory;
use App\Factories\ProviderFactory;
use App\Models\Cliente;
use App\Models\DetallePedido;
use App\Models\Pedido;
use App\Models\PedidoProveedor;
use App\Models\TransaccionPago;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

/**
 * Orquestador de pedidos multi-proveedor con soporte de pasarelas de pago.
 *
 * RESPONSABILIDADES (solo estas):
 *  - Agrupar productos por proveedor usando la tabla proveedor_productos como router.
 *  - Delegar enriquecimiento, validación, cotización y creación a cada ProveedorService.
 *  - Crear y actualizar el Pedido maestro y sus DetallePedido / PedidoProveedor.
 *  - Iniciar el flujo de pago con el gateway seleccionado (Strategy via Factory).
 *  - Procesar confirmaciones de pago (webhooks) y disparar el procesamiento del pedido.
 *  - Agregar resultados y manejar fallos parciales.
 *
 * NO HACE:
 *  - Lógica de stock, distribución por almacén ni cálculo de fletes.
 *  - Queries directas a tablas de stock o precios.
 *  - Lógica específica de ningún proveedor ni gateway.
 */
class OrchestratorOrdersService
{
    public function __construct(
        private readonly ProviderFactory       $proveedorFactory,
        private readonly PaymentGatewayFactory $paymentGatewayFactory,
    ) {}
    
    public function realizarPedidoConPago(PedidoData $datos, string $gateway = 'mercadopago'): array
{
    // Fase 1 — tiene su propia transacción DB
    $pedido = $this->crearPedido([
        'productos'     => $datos->productos->toArray(),
        'observaciones' => $datos->observaciones ?? null,
    ]);

    // Fase 1.5 — fuera de transacción DB porque llama API externa
    try {
        return $this->iniciarPago($pedido, $gateway);

    } catch (\Throwable $e) {
        // El pedido existe en BD en estado 'pendiente_pago'
        // Lo marcamos para que el cliente o admin pueda reintentar
        $pedido->update([
            'payment_status'           => 'gateway_error',
            'requiere_atencion_manual' => true,
            'errores_detallados'       => json_encode([
                'fase'      => 'iniciar_pago',
                'gateway'   => $gateway,
                'mensaje'   => $e->getMessage(),
                'timestamp' => now()->toDateTimeString(),
            ]),
        ]);

        Log::error('[Orchestrator] Pedido creado pero gateway falló', [
            'pedido_id' => $pedido->id,
            'folio'     => $pedido->folio,
            'gateway'   => $gateway,
            'error'     => $e->getMessage(),
        ]);

        // Re-lanzar con el pedido_id para que el controller
        // lo devuelva al frontend y permita reintentar
        throw new PaymentGatewayException(
            $gateway,
            $e->getMessage(),
            [
                'pedido_id' => $pedido->id,
                'folio'     => $pedido->folio,
            ],
            $e,
        );
    }
}
    // =========================================================================
    // FASE 1 — Crear pedido maestro (antes del pago)
    // =========================================================================

    /**
     * Crea el pedido maestro sin procesar subpedidos con proveedores.
     * Calcula totales (productos + envío) y persiste DetallePedido en estado pendiente.
     *
     * @param  array            $datos             ['productos' => [...], 'datos_envio' => [...], 'observaciones' => '...','metodoPago' => '...]
     * @param  string|int|null  $almacenPreferido
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

            $productosNormalizados = $this->normalizarProductos($datos['productos'] ?? []);
            $productosPorProveedor = $this->agruparProductosPorProveedor($productosNormalizados);

            $todosLosProductosEnriquecidos = [];
            $totalProductos                = 0;
            $totalEnvio                    = 0;

            foreach ($productosPorProveedor as $proveedorId => $productosDelProveedor) {
                $servicio = $this->proveedorFactory->crear($proveedorId);

                $enriquecidos = $servicio->enriquecerProductos($productosDelProveedor);
                $servicio->validarDisponibilidad($enriquecidos, $almacenPreferido);

                $cotizacion = $servicio->cotizarEnvio($enriquecidos, $cliente, $almacenPreferido);

                $totalProductos += collect($enriquecidos)->sum(fn($p) => $p->getSubtotal());
                $totalEnvio     += $cotizacion->montoTotal;

                $todosLosProductosEnriquecidos = array_merge($todosLosProductosEnriquecidos, $enriquecidos);
            }

            $intentos = 0;
            do {
                $folio = $this->generarFolio();
                $existe = DB::table('pedidos')->where('folio', $folio)->exists();
                $intentos++;
            } while ($existe);

            $pedido = Pedido::create([
                'folio'                  => $folio,
                'fecha_pedido'           => now(),
                'estatus'                => 'pendiente_pago',
                'cliente_id'             => $cliente->id,
                'precio_total'           => $totalProductos + $totalEnvio,
                'precio_total_productos' => $totalProductos,
                'precio_total_envio'     => $totalEnvio,
                'almacen_preferido'      => $almacenPreferido,
                'observaciones'          => $datos['observaciones'] ?? null,
                'payment_status'         => 'pending',
            ]);

            $this->guardarDetallesPendientes($pedido, $todosLosProductosEnriquecidos);

            return $pedido->fresh(['detalles']);
        });
    }

    // =========================================================================
    // FASE 1.5 — Iniciar pago (nuevo: con pasarela de pago)
    // =========================================================================

    /**
     * Inicia el flujo de pago con el gateway seleccionado.
     *
     * Crea la preferencia/orden en el gateway externo, persiste el gateway_order_id
     * en el pedido y registra la transacción inicial en transacciones_pagos.
     *
     * RETORNA la URL a la que el frontend debe redirigir al usuario.
     *
     * @param  Pedido  $pedido   Pedido en estado 'pendiente_pago' con payment_status 'pending'.
     * @param  string  $gateway  'mercadopago' | 'paypal'
     *
     * @return array {
     *   'redirect_url'    => string,   // URL de checkout del gateway
     *   'gateway_order_id'=> string,   // ID de preferencia/orden
     *   'sandbox_url'     => string|null,
     * }
     *
     * @throws InvalidPaymentStateException  Si el pedido no está en estado correcto.
     * @throws PaymentGatewayException       Si el gateway falla al crear la preferencia.
     */
    public function iniciarPago(Pedido $pedido, string $gateway): array
    {
        // Validar estado del pedido
        if ($pedido->estatus !== 'pendiente_pago') {
            throw new InvalidPaymentStateException($pedido->id, $pedido->estatus, 'pendiente_pago');
        }

        if (!in_array($pedido->payment_status, ['pending', null], true)) {
            throw new InvalidPaymentStateException($pedido->id, $pedido->payment_status ?? 'null', 'pending');
        }

        $gatewayService = $this->paymentGatewayFactory->crear($gateway);

        // Asegurar que los detalles estén cargados (necesarios para buildPreferencePayload)
        $pedido->loadMissing('detalles.producto', 'cliente.user');

        Log::info('[Orchestrator] Iniciando pago', [
            'pedido_id' => $pedido->id,
            'folio'     => $pedido->folio,
            'gateway'   => $gateway,
            'total'     => $pedido->precio_total,
        ]);

        $preferencia = $gatewayService->crearPreferencia($pedido);

        // Actualizar pedido con datos del gateway
        $pedido->update([
            'payment_gateway'  => $gateway,
            'gateway_order_id' => $preferencia->gatewayOrderId,
            'payment_status'   => 'pending',
        ]);

        // Registrar transacción inicial (estado pending — aún no pagado)
        TransaccionPago::create([
            'pedido_id'         => $pedido->id,
            'tipo'              => 'pago',
            'gateway'           => $gateway,
            'gateway_order_id'  => $preferencia->gatewayOrderId,
            'gateway_payment_id'=> 'PENDING_' . $preferencia->gatewayOrderId,  // placeholder hasta el webhook
            'status'            => 'pending',
            'monto'             => $pedido->precio_total,
            'moneda'            => $pedido->moneda_cobro ?? 'MXN',
            'metadata'          => $preferencia->extra,
            'ip_cliente'        => Request::ip(),
            'user_agent'        => Request::userAgent(),
        ]);

        Log::info('[Orchestrator] Preferencia creada', [
            'pedido_id'        => $pedido->id,
            'gateway_order_id' => $preferencia->gatewayOrderId,
            'redirect_url'     => $preferencia->redirectUrl,
        ]);

        return [
            'redirect_url'     => $this->resolverRedirectUrl($preferencia, $gateway),
            'gateway_order_id' => $preferencia->gatewayOrderId,
            'sandbox_url'      => $preferencia->sandboxUrl ?? null,
        ];
    }

    /**
     * Devuelve la URL de redirección correcta según el entorno.
     * En staging/local se usa sandbox_url de MercadoPago para no cobrar dinero real.
     */
    private function resolverRedirectUrl(PreferenceResponseData $preferencia, string $gateway): string
    {
        if ($gateway === 'mercadopago' && config('app.env') !== 'production' && $preferencia->sandboxUrl) {
            return $preferencia->sandboxUrl;
        }

        return $preferencia->redirectUrl;
    }

    // =========================================================================
    // FASE 1.5b — Iniciar pago SIN pasarela (nuevo: pago manual / transferencia)
    // =========================================================================

    /**
     * Marca el pedido para pago manual (transferencia, depósito, OXXO, etc.)
     * sin integrar ningún gateway externo.
     *
     * Útil para clientes empresariales con crédito o pagos bancarios.
     * El pago se confirmará manualmente por un administrador.
     *
     * @return array { 'folio' => string, 'instrucciones' => array, 'vencimiento' => string }
     *
     * @throws InvalidPaymentStateException
     */
    public function iniciarPagoManual(Pedido $pedido, string $metodoPago = 'transferencia'): array
    {
        if ($pedido->estatus !== 'pendiente_pago') {
            throw new InvalidPaymentStateException($pedido->id, $pedido->estatus, 'pendiente_pago');
        }

        $vencimiento = now()->addDays(config('payments.pago_manual.dias_vencimiento', 3));

        $pedido->update([
            'payment_gateway' => 'manual',
            'payment_status'  => 'pending',
        ]);

        // Registrar la intención de pago manual
        TransaccionPago::create([
            'pedido_id'          => $pedido->id,
            'tipo'               => 'pago',
            'gateway'            => 'manual',
            'gateway_payment_id' => 'MANUAL_' . $pedido->folio,
            'status'             => 'pending',
            'monto'              => $pedido->precio_total,
            'moneda'             => $pedido->moneda_cobro ?? 'MXN',
            'metadata'           => [
                'metodo_pago' => $metodoPago,
                'vencimiento' => $vencimiento->toDateTimeString(),
            ],
            'ip_cliente'         => Request::ip(),
            'user_agent'         => Request::userAgent(),
        ]);

        Log::info('[Orchestrator] Pago manual registrado', [
            'pedido_id'   => $pedido->id,
            'folio'       => $pedido->folio,
            'metodo_pago' => $metodoPago,
            'vencimiento' => $vencimiento,
        ]);

        $instrucciones = config("payments.pago_manual.instrucciones.{$metodoPago}", [
            'banco'   => config('payments.pago_manual.banco',   'BBVA'),
            'cuenta'  => config('payments.pago_manual.cuenta',  ''),
            'clabe'   => config('payments.pago_manual.clabe',   ''),
            'titular' => config('payments.pago_manual.titular', config('app.name')),
        ]);

        return [
            'folio'         => $pedido->folio,
            'total'         => $pedido->precio_total,
            'moneda'        => $pedido->moneda_cobro ?? 'MXN',
            'metodo_pago'   => $metodoPago,
            'instrucciones' => $instrucciones,
            'vencimiento'   => $vencimiento->toDateTimeString(),
            'referencia'    => $pedido->folio,   // usar folio como referencia bancaria
        ];
    }

    // =========================================================================
    // FASE 1.6 — Procesar webhook del gateway
    // =========================================================================

    /**
     * Procesa un webhook entrante de una pasarela de pago.
     *
     * 1. Delega al gateway la validación de firma y normalización del evento.
     * 2. Localiza el pedido afectado por gateway_order_id o external_reference.
     * 3. Actualiza transacciones_pagos con el resultado.
     * 4. Si el pago fue aprobado → llama a procesarPagoPedido().
     * 5. Si es un reembolso → actualiza montos reembolsados.
     *
     * Este método es llamado por los WebhookControllers. Es idempotente:
     * si el mismo payment_id ya fue procesado, lo ignora.
     *
     * @param  string  $gateway   'mercadopago' | 'paypal'
     * @param  array   $payload   Cuerpo del webhook ya decodificado.
     * @param  array   $headers   Cabeceras HTTP originales.
     *
     * @return bool  true si se procesó correctamente, false si fue ignorado.
     *
     * @throws PaymentWebhookException  Si la firma es inválida o el payload está malformado.
     */
    public function procesarWebhookPago(string $gateway, array $payload, array $headers): bool
    {
        $gatewayService = $this->paymentGatewayFactory->crear($gateway);

        // El gateway valida firma y normaliza el evento
        $resultado = $gatewayService->procesarWebhook($payload, $headers);

        // Ignorar eventos no relevantes (merchant_orders, etc.)
        if ($resultado->tipoEvento === 'other') {
            return false;
        }

        // Si el webhook llegó pero el estado es ambiguo (pending/in_process),
        // verificar directamente con el gateway el estado real antes de procesar
        if (in_array($resultado->status, ['pending', 'in_process'], true)) {
            try {
                $statusActual = $gatewayService->verificarPago($resultado->gatewayPaymentId);

                // Si verificando directamente sigue pending, no hacer nada todavía
                // MP enviará otro webhook cuando el estado cambie
                if (!$statusActual->aprobado()) {
                    Log::info('[Orchestrator] Pago aún no aprobado — esperando confirmación', [
                        'gateway'    => $gateway,
                        'payment_id' => $resultado->gatewayPaymentId,
                        'status'     => $statusActual->status,
                    ]);
                    return false;
                }

                // El pago ya está aprobado según la API directa — continuar
            } catch (\Throwable $e) {
                // Si falla la verificación directa, continuar con lo que trajo el webhook
                Log::warning('[Orchestrator] No se pudo verificar pago directamente', [
                    'payment_id' => $resultado->gatewayPaymentId,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        // Idempotencia: si ya existe una transacción aprobada con este payment_id, ignorar
        $yaExiste = TransaccionPago::where('gateway_payment_id', $resultado->gatewayPaymentId)
            ->where('status', 'approved')
            ->exists();

        if ($yaExiste) {
            Log::info('[Orchestrator] Webhook ignorado (ya procesado)', [
                'gateway'            => $gateway,
                'gateway_payment_id' => $resultado->gatewayPaymentId,
            ]);
            return false;
        }

        // Localizar el pedido por gateway_order_id o por external_reference en metadata
        $pedido = $this->localizarPedidoPorWebhook($gateway, $resultado);

        if (!$pedido) {
            Log::warning('[Orchestrator] Pedido no encontrado en webhook', [
                'gateway'          => $gateway,
                'gateway_order_id' => $resultado->gatewayOrderId,
                'payment_id'       => $resultado->gatewayPaymentId,
            ]);
            return false;
        }

        Log::info('[Orchestrator] Webhook recibido', [
            'pedido_id'   => $pedido->id,
            'gateway'     => $gateway,
            'tipo_evento' => $resultado->tipoEvento,
            'status'      => $resultado->status,
            'monto'       => $resultado->monto,
        ]);

        return DB::transaction(function () use ($pedido, $resultado, $gateway) {

            if ($resultado->esPago()) {
                return $this->procesarEventoPago($pedido, $resultado, $gateway);
            }

            if ($resultado->esReembolso()) {
                return $this->procesarEventoReembolso($pedido, $resultado, $gateway);
            }

            return false;
        });
    }

    /**
     * Procesa un evento de pago confirmado desde el webhook.
     */
    private function procesarEventoPago(
        Pedido           $pedido,
        WebhookResultData $resultado,
        string           $gateway,
    ): bool {
        // Actualizar o crear transacción con el resultado real
        TransaccionPago::updateOrCreate(
            [
                'pedido_id' => $pedido->id,
                'gateway'   => $gateway,
                'tipo'      => 'pago',
                // Buscar por order_id para actualizar el placeholder creado en iniciarPago()
                'gateway_order_id' => $resultado->gatewayOrderId,
            ],
            [
                'gateway_payment_id' => $resultado->gatewayPaymentId,
                'status'             => $resultado->status,
                'monto'              => $resultado->monto,
                'moneda'             => $resultado->moneda,
                'metadata'           => $resultado->metadata,
                'response_raw'       => $resultado->rawPayload,
            ],
        );

        if ($resultado->aprobado()) {
            // Actualizar campos de pago en el pedido
            $pedido->update([
                'gateway_payment_id' => $resultado->gatewayPaymentId,
                'payment_status'     => 'approved',
                'monto_pagado'       => $resultado->monto,
                'fecha_pago'         => now(),
            ]);

            // Disparar procesamiento con proveedores (Fase 2)
            return $this->procesarPagoPedido($pedido);
        }

        // Pago rechazado o pendiente — solo actualizar estado
        $pedido->update([
            'payment_status' => $resultado->status,
        ]);

        return false;
    }

    /**
     * Procesa un evento de reembolso desde el webhook.
     */
    private function procesarEventoReembolso(
        Pedido           $pedido,
        WebhookResultData $resultado,
        string           $gateway,
    ): bool {
        $esTotal   = abs($resultado->monto - (float) $pedido->precio_total) < 0.01;
        $tipoPago  = $esTotal ? 'reembolso' : 'reembolso_parcial';

        TransaccionPago::create([
            'pedido_id'          => $pedido->id,
            'tipo'               => $tipoPago,
            'gateway'            => $gateway,
            'gateway_order_id'   => $resultado->gatewayOrderId,
            'gateway_payment_id' => $resultado->gatewayPaymentId,
            'status'             => $resultado->status,
            'monto'              => $resultado->monto,
            'moneda'             => $resultado->moneda,
            'metadata'           => $resultado->metadata,
            'response_raw'       => $resultado->rawPayload,
            'motivo'             => 'Reembolso procesado por gateway',
        ]);

        $pedido->increment('monto_reembolsado', $resultado->monto);
        $pedido->update([
            'payment_status' => $esTotal ? 'refunded' : 'partial_refunded',
        ]);

        Log::info('[Orchestrator] Reembolso procesado', [
            'pedido_id' => $pedido->id,
            'monto'     => $resultado->monto,
            'tipo'      => $tipoPago,
        ]);

        return true;
    }

    /**
     * Localiza el pedido afectado por un webhook usando múltiples estrategias.
     */
    private function localizarPedidoPorWebhook(string $gateway, WebhookResultData $resultado): ?Pedido
    {
        // Estrategia 1: por gateway_order_id (el más confiable)
        if ($resultado->gatewayOrderId) {
            $pedido = Pedido::where('gateway_order_id', $resultado->gatewayOrderId)
                ->where('payment_gateway', $gateway)
                ->first();

            if ($pedido) return $pedido;
        }

        // Estrategia 2: por external_reference (folio) en el metadata del resultado
        $externalRef = $resultado->metadata['external_reference'] ?? null;

        if ($externalRef) {
            $pedido = Pedido::where('folio', $externalRef)->first();
            if ($pedido) return $pedido;
        }

        return null;
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

            $cliente          = $pedido->cliente;
            $almacenPreferido = $pedido->almacen_preferido;

            $detallesPendientes = $pedido->detalles()
                ->whereNull('pedido_proveedor_id')
                ->with('producto')
                ->get()
                ->toArray();

            if (empty($detallesPendientes)) {
                Log::error('[Orchestrator] No hay productos pendientes para procesar', ['pedido_id' => $pedido->id]);
                return false;
            }

            $detallesPorProveedor = $this->agruparDetallesPorProveedorId($detallesPendientes);

            $totalProductos    = 0;
            $totalEnvio        = 0;
            $exitosos          = 0;
            $fallidos          = 0;
            $erroresDetallados = [];

            foreach ($detallesPorProveedor as $proveedorId => $detalles) {
                $resultado = $this->procesarSubpedido(
                    $pedido, $proveedorId, $detalles, $cliente, $almacenPreferido,
                );

                if ($resultado !== null) {
                    $totalProductos += $resultado['subtotal'];
                    $totalEnvio     += $resultado['envio'];
                    $exitosos++;

                    Log::info('[Orchestrator] Subpedido procesado exitosamente', [
                        'pedido_id'    => $pedido->id,
                        'proveedor_id' => $proveedorId,
                        'folios'       => $resultado['folios'],
                    ]);
                } else {
                    $fallidos++;
                    $error               = [
                        'proveedor_id'        => $proveedorId,
                        'productos_afectados' => collect($detalles)->pluck('clave_proveedor')->toArray(),
                        'timestamp'           => now()->toDateTimeString(),
                    ];
                    $erroresDetallados[] = $error;
                    $this->registrarSubpedidoFallido($pedido, $proveedorId, $detalles, $error);

                    Log::error('[Orchestrator] Subpedido falló — continuando con otros proveedores', [
                        'pedido_id'    => $pedido->id,
                        'proveedor_id' => $proveedorId,
                    ]);
                }
            }

            $estatusInfo = $this->determinarEstatusFinal($exitosos, $fallidos, $erroresDetallados);

            $pedido->update([
                'estatus'                  => $estatusInfo['estatus'],
                'errores_detallados'       => $erroresDetallados ? json_encode([
                    'mensaje'  => $estatusInfo['mensaje'],
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
     * @throws ShippingOutOfRangeException
     * @throws ShippingQuoteException
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
                $servicio             = $this->proveedorFactory->crear($proveedorId);
                $productosParaCotizar = $servicio->prepararParaCotizacion($productosDelProveedor);
                $cotizacion           = $servicio->cotizarEnvio($productosParaCotizar, $cliente, $almacenPreferido);

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
                throw $e;
            } catch (ShippingQuoteException $e) {
                $erroresProveedores[] = ['proveedor_id' => $proveedorId, 'error' => $e->getMessage(), 'tipo' => 'cotizacion'];
                Log::warning('[Orchestrator] Fallo cotización de envío', ['proveedor_id' => $proveedorId, 'error' => $e->getMessage()]);
            } catch (\Exception $e) {
                $erroresProveedores[] = ['proveedor_id' => $proveedorId, 'error' => $e->getMessage(), 'tipo' => 'inesperado'];
                Log::error('[Orchestrator] Error inesperado al cotizar envío', ['proveedor_id' => $proveedorId, 'error' => $e->getMessage()]);
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

    private function normalizarProductos(array $productos): array
    {
        return collect($productos)->map(fn($p) => [
            'codigo_proveedor' => $p['clave'],
            'cantidad'         => (int) $p['cantidad'],
        ])->toArray();
    }

    /** @throws ProductNotFoundException */
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

    private function agruparDetallesPorProveedorId(array $detalles): array
    {
        $agrupados = [];

        foreach ($detalles as $detalle) {
            $proveedorId                   = $detalle['producto']['proveedor_id'];
            $agrupados[$proveedorId][]     = $detalle;
        }

        return $agrupados;
    }

    // =========================================================================
    // PRIVADOS — procesamiento de subpedidos
    // =========================================================================

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
                numeroOrden:      $pedido->folio,
                productos:        collect($detalles)->map(fn($d) => [
                    'proveedor_producto_id' => $d['proveedor_producto_id'],
                    'codigo_proveedor'      => $d['clave_proveedor'],
                    'cantidad'              => $d['cantidad'],
                    'precio_unitario'       => $d['precio_unitario'],
                ])->toArray(),
                datosEnvio:       $pedido->datos_envio,
                test:             config('app.env') !== 'production',
                almacenPreferido: $almacenPreferido,
            );

            $response = $servicio->crearPedido($request, $cliente);

            if (!$response['success']) {
                Log::error('[Orchestrator] Proveedor rechazó el subpedido', [
                    'pedido_id'    => $pedido->id,
                    'proveedor_id' => $proveedorId,
                    'error'        => $response['error'] ?? 'Error desconocido',
                ]);
                return null;
            }

            $ordenes   = isset($response['data'][0]) ? $response['data'] : [$response['data']];
            $resultado = ['subtotal' => 0, 'envio' => 0, 'total' => 0, 'folios' => [], 'pedidos_proveedor_ids' => []];

            foreach ($ordenes as $ordenData) {
                $pedidoProveedor = PedidoProveedor::create([
                    'pedido_id'                  => $pedido->id,
                    'proveedor_id'               => $proveedorId,
                    'folio_pedido'               => $ordenData['folio_pedido']               ?? null,
                    'moneda_cobro_productos'      => $ordenData['moneda_cobro_productos']      ?? 'USD',
                    'precio_total_productos'      => $ordenData['precio_total_productos']      ?? 0,
                    'moneda_cobro_envio'          => $ordenData['moneda_cobro_envio']          ?? 'MXN',
                    'precio_total_envio'          => $ordenData['precio_total_envio']          ?? 0,
                    'iva_incluido'                => $ordenData['iva_incluido']                ?? false,
                    'envio_gratis'                => $ordenData['envio_gratis']                ?? false,
                    'fecha_entrega_estimada'      => $ordenData['fecha_entrega_estimada']      ?? null,
                    'status'                      => $ordenData['status']                      ?? 'en_proceso',
                    'tipo_cambio_aplicado'        => $ordenData['tipo_cambio_aplicado']        ?? null,
                    'precio_total_productos_mxn'  => $ordenData['precio_total_productos_mxn']  ?? 0,
                    'precio_total_envio_mxn'      => $ordenData['precio_total_envio_mxn']      ?? 0,
                    'precio_total_mxn'            => $ordenData['precio_total_mxn']            ?? 0,
                    'email_agente'                => $ordenData['email_agente']                ?? $ordenData['emailAgente'] ?? null,
                    'email_almacen'               => $ordenData['email_almacen']               ?? $ordenData['emailAlmacen'] ?? null,
                    'origen_envio'                => $ordenData['origen_envio']                ?? $ordenData['origen'] ?? null,
                ]);

                $resultado['pedidos_proveedor_ids'][] = $pedidoProveedor->id;
                $resultado['folios'][]                 = $ordenData['folio_pedido'];
                $resultado['subtotal']                += $ordenData['precio_total_productos_mxn'] ?? 0;
                $resultado['envio']                   += $ordenData['precio_total_envio_mxn']     ?? 0;
                $resultado['total']                   += $ordenData['precio_total_mxn']           ?? 0;
            }

            $idsDetalles = collect($detalles)->pluck('id')->toArray();
            DetallePedido::whereIn('id', $idsDetalles)
                ->update(['pedido_proveedor_id' => $resultado['pedidos_proveedor_ids'][0]]);

            return $resultado;

        } catch (\Exception $e) {
            Log::error('[Orchestrator] Excepción al procesar subpedido', [
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
                'moneda_cobro_productos'   => 'MXN',
                'precio_total_productos'   => collect($detalles)->sum('subtotal'),
                'precio_total_envio'       => 0,
                'precio_total_mxn'         => collect($detalles)->sum('subtotal'),
                'status'                   => 'fallido',
                'error_mensaje'            => 'Error al procesar con el proveedor',
                'error_detalle'            => json_encode([
                    'detalles'           => $detalles,
                    'error'              => $error,
                    'requiere_reembolso' => true,
                ]),
                'requiere_atencion_manual' => true,
            ]);
        } catch (\Exception $e) {
            Log::error('[Orchestrator] Error al registrar subpedido fallido', [
                'pedido_id'    => $pedido->id,
                'proveedor_id' => $proveedorId,
                'error'        => $e->getMessage(),
            ]);
        }
    }

    // =========================================================================
    // PRIVADOS — helpers
    // =========================================================================

    private function resolverCliente(): Cliente
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
        Log::critical('[Orchestrator] ATENCIÓN REQUERIDA: Pedido con fallos parciales', [
            'pedido_id'        => $pedido->id,
            'folio'            => $pedido->folio,
            'cliente_id'       => $pedido->cliente_id,
            'total_cobrado'    => $pedido->precio_total,
            'errores'          => $errores,
            'accion_requerida' => 'Revisar y procesar reembolso de productos no entregados',
        ]);
    }

    private function generarFolio(): string
    {
        return sprintf(
            'NXTITPED-%s-%s',
            now()->format('Ymd'),
            strtoupper(substr(uniqid(), -6))
        );
    }
}