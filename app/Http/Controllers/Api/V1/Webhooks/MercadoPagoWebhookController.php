<?php

namespace App\Http\Controllers\Api\V1\Webhooks;

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

        // Normalizar headers a string (algunos vienen como array)
        $headersNormalizados = collect($headers)
            ->map(fn($v) => is_array($v) ? ($v[0] ?? '') : $v)
            ->toArray();

        Log::info('[Webhook/MercadoPago] Webhook recibido', [
            'type'       => $payload['type']    ?? $payload['topic'] ?? 'unknown',
            'data_id'    => $payload['data']['id'] ?? null,
            'request_id' => $headersNormalizados['x-request-id'] ?? null,
        ]);

        try {
            $procesado = $this->orchestrator->procesarWebhookPago(
                gateway: 'mercadopago',
                payload: $payload,
                headers: $headersNormalizados,
            );

            $mensaje = $procesado ? 'Webhook procesado' : 'Webhook ignorado (evento no relevante o duplicado)';

            Log::info("[Webhook/MercadoPago] {$mensaje}", [
                'data_id' => $payload['data']['id'] ?? null,
            ]);

            // MercadoPago necesita 200 sí o sí, aunque el evento sea ignorado
            return response('OK', 200);

        } catch (PaymentWebhookException $e) {
            // Firma inválida o payload malformado → 400 para que MP no reintente
            Log::warning('[Webhook/MercadoPago] Webhook inválido', [
                'error'   => $e->getMessage(),
                'payload' => $payload,
            ]);

            return response('Bad Request', 400);

        } catch (\Throwable $e) {
            // Error inesperado → 500 para que MP reintente
            Log::error('[Webhook/MercadoPago] Error inesperado', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response('Internal Server Error', 500);
        }
    }
}
