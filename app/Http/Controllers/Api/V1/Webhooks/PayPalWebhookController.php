<?php

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Exceptions\PaymentWebhookException;
use App\Http\Controllers\Controller;
use App\Services\Orders\OrchestratorOrdersService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Recibe y procesa webhooks de PayPal.
 *
 * IMPORTANTE: Esta ruta debe estar excluida de CSRF.
 * URL a registrar en el panel de PayPal:
 *   https://tudominio.com/webhooks/paypal
 *
 * Eventos a suscribirse en PayPal Developer Dashboard:
 *   - PAYMENT.CAPTURE.COMPLETED
 *   - PAYMENT.CAPTURE.REFUNDED
 *   - PAYMENT.CAPTURE.REVERSED
 */
class PayPalWebhookController extends Controller
{
    public function __construct(
        private readonly OrchestratorOrdersService $orchestrator,
    ) {}

    /**
     * POST /webhooks/paypal
     */
    public function handle(Request $request): Response
    {
        $payload = $request->json()->all();
        $headers = collect($request->headers->all())
            ->map(fn($v) => is_array($v) ? ($v[0] ?? '') : $v)
            ->toArray();

        Log::info('[Webhook/PayPal] Webhook recibido', [
            'event_type' => $payload['event_type'] ?? 'unknown',
            'resource_id'=> $payload['resource']['id'] ?? null,
        ]);

        try {
            $procesado = $this->orchestrator->procesarWebhookPago(
                gateway: 'paypal',
                payload: $payload,
                headers: $headers,
            );

            Log::info('[Webhook/PayPal] ' . ($procesado ? 'Procesado' : 'Ignorado'), [
                'event_type' => $payload['event_type'] ?? 'unknown',
            ]);

            return response('OK', 200);

        } catch (PaymentWebhookException $e) {
            Log::warning('[Webhook/PayPal] Webhook inválido', ['error' => $e->getMessage()]);
            return response('Bad Request', 400);

        } catch (\Throwable $e) {
            Log::error('[Webhook/PayPal] Error inesperado', ['error' => $e->getMessage()]);
            return response('Internal Server Error', 500);
        }
    }
}
