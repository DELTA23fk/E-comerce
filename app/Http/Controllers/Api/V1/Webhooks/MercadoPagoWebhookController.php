<?php

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Exceptions\PaymentGatewayException;
use App\Exceptions\PaymentWebhookException;
use App\Http\Controllers\Controller;
use App\Services\Orders\OrchestratorOrdersService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Recibe y procesa webhooks de MercadoPago.
 *
 * IMPORTANTE: Esta ruta debe estar EXCLUIDA del middleware VerifyCsrfToken.
 *
 * Registrar en bootstrap/app.php (Laravel 12):
 *   ->withMiddleware(function (Middleware $middleware) {
 *       $middleware->validateCsrfTokens(except: [
 *           'webhooks/mercadopago',
 *       ]);
 *   })
 *
 * URL a configurar en el panel de MercadoPago:
 *   https://tudominio.com/webhooks/mercadopago
 */

class MercadoPagoWebhookController extends Controller
{
    public function __construct(
        private readonly OrchestratorOrdersService $orchestrator,
    ) {}

    /**
     * POST /webhooks/mercadopago
     *
     * MercadoPago espera una respuesta HTTP 200 en menos de 500ms.
     * Si no responde a tiempo, reintenta el webhook hasta 3 veces.
     * Por eso: validar rápido y si el procesamiento es pesado, encolar.
     */
    public function handle(Request $request): Response
    {
        $payload = $request->json()->all();
        $headers = $request->headers->all();

        $headersNormalizados = collect($headers)
            ->map(fn($v) => is_array($v) ? ($v[0] ?? '') : $v)
            ->toArray();

        Log::info('[Webhook/MercadoPago] Webhook recibido', [
            'type'       => $payload['type']       ?? $payload['topic'] ?? 'unknown',
            'data_id'    => $payload['data']['id'] ?? null,
            'request_id' => $headersNormalizados['x-request-id'] ?? null,
        ]);

        try {
            $procesado = $this->orchestrator->procesarWebhookPago(
                gateway: 'mercadopago',
                payload: $payload,
                headers: $headersNormalizados,
            );

            $mensaje = $procesado
                ? 'Webhook procesado'
                : 'Webhook ignorado (evento no relevante o duplicado)';

            Log::info("[Webhook/MercadoPago] {$mensaje}", [
                'data_id' => $payload['data']['id'] ?? null,
            ]);

            return response('OK', 200);

        } catch (PaymentWebhookException $e) {
            // Firma inválida o payload malformado → 400
            // MP NO debe reintentar — el request está genuinamente mal formado
            Log::warning('[Webhook/MercadoPago] Webhook inválido', [
                'error'   => $e->getMessage(),
                'payload' => $payload,
            ]);

            return response('Bad Request', 400);

        } catch (PaymentGatewayException $e) {
            // Pago no encontrado, API de MP caída, timeout, ID ficticio del simulador
            // → 200 para que MP NO reintente (no es culpa del request)
            Log::warning('[Webhook/MercadoPago] No se pudo procesar pago', [
                'error'   => $e->getMessage(),
                'data_id' => $payload['data']['id'] ?? null,
            ]);

            return response('OK', 200);

        } catch (\Throwable $e) {
            // Error inesperado nuestro → 500 para que MP sí reintente
            Log::error('[Webhook/MercadoPago] Error inesperado', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response('Internal Server Error', 500);
        }
    }
}
