<?php

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Exceptions\PaymentGatewayException;
use App\Exceptions\PaymentWebhookException;
use App\Http\Controllers\Controller;
use App\Services\Orders\OrchestratorOrdersService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class MercadoPagoWebhookController extends Controller
{
    public function __construct(
        private readonly OrchestratorOrdersService $orchestrator,
    ) {}

    public function handle(Request $request): Response
{
    Log::info('[Webhook/MercadoPago] Webhook recibido', [
        'query'   => $request->query(),
        'body'    => $request->json()->all(),
        'headers' => $request->headers->all(),
    ]);

    $bodyPayload    = $request->json()->all();
    $rawQueryString = $request->server('QUERY_STRING', '');
    $queryParams    = $this->parseQueryString($rawQueryString);

    // Detectar formato y normalizar antes de combinar
    $format  = $this->detectarFormatoMP($queryParams, $bodyPayload);
    $payload = $this->normalizarPayload($queryParams, $bodyPayload, $format);

    Log::info('[Webhook/MercadoPago] Payload combinado', [
        'payload' => $payload,
        'formato' => $format,
    ]);

    try {
        $procesado = $this->orchestrator->procesarWebhookPago(
            gateway: 'mercadopago',
            payload: $payload,
            headers: $request->headers->all(),
        );

        Log::info('[Webhook/MercadoPago] ' . ($procesado ? 'Procesado' : 'Ignorado'), [
            'data_id' => $payload['_data_id'] ?? null,
        ]);

        return response('OK', 200);

    } catch (PaymentWebhookException $e) {
        Log::warning('[Webhook/MercadoPago] Webhook inválido', [
            'error'   => $e->getMessage(),
            'payload' => $payload,
        ]);
        return response('Bad Request', 400);

    } catch (PaymentGatewayException $e) {
        Log::warning('[Webhook/MercadoPago] No se pudo procesar pago', [
            'error'   => $e->getMessage(),
            'data_id' => $payload['_data_id'] ?? null,
        ]);
        return response('OK', 200);

    } catch (\Throwable $e) {
        Log::error('[Webhook/MercadoPago] Error inesperado', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        return response('Internal Server Error', 500);
    }
}

/**
 * Determina si la notificación es el nuevo formato Webhook o el IPN legacy.
 *
 * Nuevo Webhook: query contiene "data.id" (con punto literal)
 * IPN legacy:    query contiene "id" + "topic", body tiene "resource"
 */
private function detectarFormatoMP(array $queryParams, array $body): string
{
    // Indicador explícito del nuevo formato Webhook
    if (($queryParams['source_news'] ?? '') === 'webhooks') {
        return 'webhook';
    }

    // Nuevo formato sin source_news pero con data.id en query
    if (isset($queryParams['data.id'])) {
        return 'webhook';
    }

    // IPN legacy: query lleva id + topic, body lleva resource
    if (isset($queryParams['topic']) || isset($queryParams['id'])) {
        return 'ipn';
    }

    // Fallback: body con estructura nueva
    if (isset($body['action'], $body['data']['id'])) {
        return 'webhook';
    }

    return 'ipn';
}

private function normalizarPayload(array $queryParams, array $body, string $format): array
{
    // ⚠️ No hacer array_merge ciego: en el nuevo formato el body trae
    // "id" = ID de la notificación (ej. 130499573009) y el query trae
    // "data.id" = ID del pago (ej. 153721307902). Un merge los mezclaría.
    // Mantenemos ambos separados y exponemos _data_id con el valor correcto.

    $payload = match ($format) {
        'webhook' => [
            // Del body tomamos los campos de la notificación
            'action'        => $body['action']       ?? null,
            'api_version'   => $body['api_version']  ?? null,
            'date_created'  => $body['date_created'] ?? null,
            'notification_id' => $body['id']         ?? null, // ID notificación
            'live_mode'     => $body['live_mode']    ?? null,
            'type'          => $body['type']         ?? $queryParams['type'] ?? null,
            'user_id'       => $body['user_id']      ?? null,
            // Del query tomamos el ID del pago (preservado con punto)
            'data.id'       => $queryParams['data.id']  ?? null,
            'source_news'   => $queryParams['source_news'] ?? null,
        ],
        'ipn' => [
            'topic'    => $queryParams['topic'] ?? $body['topic'] ?? null,
            'resource' => $body['resource']     ?? $queryParams['id'] ?? null,
        ],
        default => array_merge($queryParams, $body),
    };

    $payload['_data_id'] = match ($format) {
        'webhook' => (string) ($queryParams['data.id'] ?? $body['data']['id'] ?? ''),
        'ipn'     => (string) ($queryParams['id']      ?? $body['resource']   ?? ''),
        default   => null,
    };

    $payload['_format'] = $format;

    return $payload;
}

private function parseQueryString(string $queryString): array
{
    $result = [];
    foreach (explode('&', $queryString) as $part) {
        if (!str_contains($part, '=')) continue;
        [$key, $value] = explode('=', $part, 2);
        $result[urldecode($key)] = urldecode($value);
    }
    return $result;
}
}