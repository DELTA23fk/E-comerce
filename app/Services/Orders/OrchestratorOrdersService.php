<?php

namespace App\Services\Orders;

use App\Data\Pedidos\PedidoData;
use App\Exceptions\PaymentGatewayException;
use App\Models\Cliente;
use App\Models\Pedido;
use Illuminate\Support\Facades\Log;

/**
 * Orquestador de pedidos multi-proveedor con soporte de pasarelas de pago.
 *
 * ÚNICO PUNTO DE ENTRADA para controllers y jobs externos.
 * No contiene lógica de negocio propia — solo coordina y delega.
 *
 * Servicios internos (no invocar directamente desde fuera):
 *  - OrderCreationService        Fase 1  — crearPedido
 *  - PaymentFlowService          Fase 1.5 — iniciarPago, iniciarPagoManual
 *  - WebhookProcessorService     Fase 1.6 — procesarWebhookPago
 *  - SuborderRegistrationService Fase 2  — procesarPagoPedido
 *  - RefundService               — emitirReembolso
 *  - ShippingQuoteService        — cotizarEnvioProductos
 *
 * NO HACE:
 *  - Lógica de stock, distribución por almacén ni cálculo de fletes.
 *  - Queries directas a tablas de stock o precios.
 *  - Lógica específica de ningún proveedor ni gateway.
 *  - Llamadas a APIs de proveedores (procesamiento manual posterior).
 */
class OrchestratorOrdersService
{
    public function __construct(
        private readonly OrderCreationService        $orderCreation,
        private readonly PaymentFlowService          $paymentFlow,
        private readonly WebhookProcessorService     $webhookProcessor,
        private readonly SuborderRegistrationService $suborderRegistration,
        private readonly RefundService               $refundService,
        private readonly ShippingQuoteService        $shippingQuote,
    ) {}

    // =========================================================================
    // FLUJO PRINCIPAL
    // =========================================================================

    /**
     * Crea el pedido maestro e inicia el pago en un solo paso.
     * Es el método que llaman los controllers de checkout.
     *
     * @throws PaymentGatewayException  Con pedido_id incluido para reintentar desde el frontend.
     */
    public function realizarPedidoConPago(PedidoData $datos, string $gateway = 'mercadopago'): array
    {
        $pedido = $this->orderCreation->crearPedido([
            'productos'     => $datos->productos->toArray(),
            'observaciones' => $datos->observaciones ?? null,
        ]);

        try {
            return $this->paymentFlow->iniciarPago($pedido, $gateway);

        } catch (\Throwable $e) {
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

            throw new PaymentGatewayException(
                $gateway,
                $e->getMessage(),
                ['pedido_id' => $pedido->id, 'folio' => $pedido->folio],
                $e,
            );
        }
        catch (\MercadoPago\Exceptions\MPApiException $e) {
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
                'errorApi'     => $e->getApiResponse(),
                'content'    => $e->getContent(),
            ]);

            throw new PaymentGatewayException(
                $gateway,
                $e->getMessage(),
                ['pedido_id' => $pedido->id, 'folio' => $pedido->folio],
                $e,
            );
        }
    }

    // =========================================================================
    // FASE 1 — Crear pedido
    // =========================================================================

    public function crearPedido(array $datos, string|int|null $almacenPreferido = null): Pedido
    {
        return $this->orderCreation->crearPedido($datos, $almacenPreferido);
    }

    // =========================================================================
    // FASE 1.5 — Iniciar pago
    // =========================================================================

    public function iniciarPago(Pedido $pedido, string $gateway): array
    {
        return $this->paymentFlow->iniciarPago($pedido, $gateway);
    }

    public function iniciarPagoManual(Pedido $pedido, string $metodoPago = 'transferencia'): array
    {
        return $this->paymentFlow->iniciarPagoManual($pedido, $metodoPago);
    }

    // =========================================================================
    // FASE 1.6 — Webhook
    // =========================================================================

    public function procesarWebhookPago(string $gateway, array $payload, array $headers): bool
    {
        return $this->webhookProcessor->procesar($gateway, $payload, $headers);
    }

    // =========================================================================
    // FASE 2 — Registrar subpedidos post-pago
    // =========================================================================

    public function procesarPagoPedido(Pedido $pedido): bool
    {
        return $this->suborderRegistration->procesar($pedido);
    }

    // =========================================================================
    // REEMBOLSOS
    // =========================================================================

    /**
     * @param  float|null  $monto  null = reembolso total del saldo pendiente
     */
    public function emitirReembolso(
        Pedido  $pedido,
        ?float  $monto  = null,
        string  $motivo = 'Reembolso solicitado por administrador',
        ?string $admin  = null,
    ): array {
        return $this->refundService->emitir($pedido, $monto, $motivo, $admin);
    }

    // =========================================================================
    // COTIZACIÓN RÁPIDA
    // =========================================================================

    public function cotizarEnvioProductos(
        array           $productos,
        Cliente         $cliente,
        string|int|null $almacenPreferido = null,
    ): array {
        return $this->shippingQuote->cotizar($productos, $cliente, $almacenPreferido);
    }
}