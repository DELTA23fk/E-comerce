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
        $format         = $this->detectarFormatoMP($queryParams, $bodyPayload);
        $payload        = $this->normalizarPayload($queryParams, $bodyPayload, $format);

        Log::info('[Webhook/MercadoPago] Payload combinado', [
            'payload' => $payload,
            'formato' => $format,
        ]);

        try {
            $procesado = $this->orchestrator->procesarWebhookPago(
                gateway:  'mercadopago',
                payload:  $payload,
                headers:  $request->headers->all(),
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

    private function detectarFormatoMP(array $queryParams, array $body): string
    {
        if (($queryParams['source_news'] ?? '') === 'webhooks') return 'webhook';
        if (isset($queryParams['data.id']))                      return 'webhook';
        if (isset($queryParams['topic']) || isset($queryParams['id'])) return 'ipn';
        if (isset($body['action'], $body['data']['id']))         return 'webhook';
        return 'ipn';
    }

    private function normalizarPayload(array $queryParams, array $body, string $format): array
    {
        $payload = match ($format) {
            'webhook' => [
                'action'          => $body['action']       ?? null,
                'api_version'     => $body['api_version']  ?? null,
                'date_created'    => $body['date_created'] ?? null,
                'notification_id' => $body['id']           ?? null,
                'live_mode'       => $body['live_mode']    ?? null,
                'type'            => $body['type']         ?? $queryParams['type']  ?? null,
                'user_id'         => $body['user_id']      ?? null,
                'data.id'         => $queryParams['data.id'] ?? $body['data']['id'] ?? null,
                'source_news'     => $queryParams['source_news'] ?? null,
            ],
            'ipn' => [
                'topic'    => $queryParams['topic'] ?? $body['topic']    ?? null,
                'resource' => $body['resource']     ?? $queryParams['id'] ?? null,
            ],
            default => array_merge($queryParams, $body),
        };

        // ✅ _data_id es la referencia canónica usada por toda la capa de servicios
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