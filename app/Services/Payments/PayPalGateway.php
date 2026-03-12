<?php
namespace App\Services\Payments;

use App\Contratos\PaymentGatewayInterface;
use App\Data\Payment\PaymentStatusData;
use App\Data\Payment\PreferenceResponseData;
use App\Data\Payment\ReembolsoData;
use App\Data\Payment\WebhookResultData;
use App\Exceptions\PaymentGatewayException;
use App\Exceptions\PaymentWebhookException;
use App\Models\Pedido;
use Illuminate\Support\Facades\Http;

/**
 * Implementación del Strategy para PayPal (Orders API v2).
 *
 * Docs: https://developer.paypal.com/docs/api/orders/v2/
 *
 * Flujo:
 *  1. crearPreferencia() → POST /v2/checkout/orders → devuelve approve link
 *  2. Usuario aprueba en PayPal
 *  3. procesarWebhook()  → valida PAYPAL-TRANSMISSION-SIG → captura la orden
 *  4. verificarPago()    → GET /v2/checkout/orders/{id} o /v2/payments/captures/{id}
 *  5. emitirReembolso()  → POST /v2/payments/captures/{id}/refund
 */
class PayPalGateway implements PaymentGatewayInterface
{
    private const NOMBRE      = 'paypal';
    private const API_BASE    = 'https://api-m.paypal.com';   // sandbox: api-m.sandbox.paypal.com
    private const SANDBOX_BASE = 'https://api-m.sandbox.paypal.com';

    private const STATUS_MAP = [
        'CREATED'          => 'pending',
        'SAVED'            => 'pending',
        'APPROVED'         => 'pending',    // aprobado pero no capturado aún
        'VOIDED'           => 'cancelled',
        'COMPLETED'        => 'approved',   // captura exitosa
        'PAYER_ACTION_REQUIRED' => 'pending',
    ];

    private ?string $cachedAccessToken = null;

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $webhookId,     // necesario para validar firma
        private readonly bool   $sandbox = false,
    ) {}

    // =========================================================================
    // CREAR PREFERENCIA
    // =========================================================================

    public function crearPreferencia(Pedido $pedido): PreferenceResponseData
    {
        $token   = $this->obtenerAccessToken();
        $payload = $this->buildOrderPayload($pedido);

        $response = $this->post('/v2/checkout/orders', $payload, $token);

        if (!isset($response['id'])) {
            throw new PaymentGatewayException(self::NOMBRE, 'No se recibió order ID de PayPal.', $response);
        }

        // Buscar el link de aprobación
        $approveLink = collect($response['links'] ?? [])
            ->firstWhere('rel', 'approve')['href'] ?? null;

        if (!$approveLink) {
            throw new PaymentGatewayException(self::NOMBRE, 'No se encontró approve link en la respuesta.', $response);
        }

        return new PreferenceResponseData(
            gatewayOrderId: $response['id'],
            redirectUrl:    $approveLink,
            extra: [
                'status'     => $response['status']     ?? null,
                'links'      => $response['links']      ?? [],
                'create_time'=> $response['create_time'] ?? null,
            ],
        );
    }

    private function buildOrderPayload(Pedido $pedido): array
    {
        $baseUrl = config('app.url');

        return [
            'intent'              => 'CAPTURE',
            'purchase_units'      => [[
                'reference_id'    => $pedido->folio,
                'description'     => "Pedido {$pedido->folio}",
                'amount'          => [
                    'currency_code' => 'MXN',
                    'value'         => number_format($pedido->precio_total, 2, '.', ''),
                    'breakdown'     => [
                        'item_total' => [
                            'currency_code' => 'MXN',
                            'value'         => number_format($pedido->precio_total_productos, 2, '.', ''),
                        ],
                        'shipping' => [
                            'currency_code' => 'MXN',
                            'value'         => number_format($pedido->precio_total_envio, 2, '.', ''),
                        ],
                    ],
                ],
                'items'           => $pedido->detalles->map(fn($d) => [
                    'name'        => $d->producto?->nombre ?? $d->clave_proveedor,
                    'sku'         => $d->clave_proveedor,
                    'unit_amount' => ['currency_code' => 'MXN', 'value' => number_format($d->precio_unitario, 2, '.', '')],
                    'quantity'    => (string) $d->cantidad,
                ])->toArray(),
            ]],
            'payment_source' => [
                'paypal' => [
                    'experience_context' => [
                        'return_url' => "{$baseUrl}/pagos/resultado?gateway=paypal&status=success&folio={$pedido->folio}",
                        'cancel_url' => "{$baseUrl}/pagos/resultado?gateway=paypal&status=cancel&folio={$pedido->folio}",
                    ],
                ],
            ],
        ];
    }

    // =========================================================================
    // VERIFICAR PAGO
    // =========================================================================

    public function verificarPago(string $gatewayPaymentId): PaymentStatusData
    {
        $token = $this->obtenerAccessToken();

        // Intentar como capture_id primero, luego como order_id
        try {
            $data = $this->get("/v2/payments/captures/{$gatewayPaymentId}", $token);

            return new PaymentStatusData(
                gatewayPaymentId: $data['id'],
                status:           $data['status'] === 'COMPLETED' ? 'approved' : 'pending',
                statusRaw:        $data['status'],
                monto:            (float) ($data['amount']['value'] ?? 0),
                moneda:           $data['amount']['currency_code'] ?? 'MXN',
                meta:             $data,
            );
        } catch (PaymentGatewayException) {
            // Si falla, consultamos como order
            $data = $this->get("/v2/checkout/orders/{$gatewayPaymentId}", $token);

            return new PaymentStatusData(
                gatewayPaymentId: $data['id'],
                status:           self::STATUS_MAP[$data['status']] ?? 'pending',
                statusRaw:        $data['status'],
                monto:            (float) ($data['purchase_units'][0]['amount']['value'] ?? 0),
                moneda:           $data['purchase_units'][0]['amount']['currency_code'] ?? 'MXN',
                meta:             $data,
            );
        }
    }

    // =========================================================================
    // PROCESAR WEBHOOK
    // =========================================================================

    public function procesarWebhook(array $payload, array $headers): WebhookResultData
    {
        $this->validarFirmaWebhook($payload, $headers);

        $eventType = $payload['event_type'] ?? '';

        if (!in_array($eventType, [
            'PAYMENT.CAPTURE.COMPLETED',
            'PAYMENT.CAPTURE.REFUNDED',
            'PAYMENT.CAPTURE.REVERSED',
        ], true)) {
            return new WebhookResultData(
                tipoEvento: 'other', status: 'ignored',
                gatewayPaymentId: '', gatewayOrderId: null,
                monto: 0, moneda: 'MXN', rawPayload: $payload,
            );
        }

        $resource    = $payload['resource'] ?? [];
        $captureId   = $resource['id']      ?? '';
        $orderId     = $resource['supplementary_data']['related_ids']['order_id'] ?? null;
        $monto       = (float) ($resource['amount']['value'] ?? 0);
        $moneda      = $resource['amount']['currency_code'] ?? 'MXN';

        $tipoEvento = match (true) {
            str_contains($eventType, 'REFUNDED'), str_contains($eventType, 'REVERSED') => 'refund',
            default => 'payment',
        };

        $status = match ($eventType) {
            'PAYMENT.CAPTURE.COMPLETED' => 'approved',
            default                     => 'refunded',
        };

        return new WebhookResultData(
            tipoEvento:       $tipoEvento,
            status:           $status,
            gatewayPaymentId: $captureId,
            gatewayOrderId:   $orderId,
            monto:            $monto,
            moneda:           $moneda,
            rawPayload:       $payload,
            metadata: [
                'payer_email'         => $resource['payer']['email_address'] ?? null,
                'payer_id'            => $resource['payer']['payer_id']       ?? null,
                'last_digits'         => $resource['payment_source']['card']['last_digits'] ?? null,
                'event_type'          => $eventType,
            ],
        );
    }

    /**
     * Valida la firma de PayPal usando su endpoint de verificación.
     * Documentación: https://developer.paypal.com/api/rest/webhooks/rest/#link-eventtypeforwebhooks
     */
    private function validarFirmaWebhook(array $payload, array $headers): void
    {
        if ($this->sandbox && config('app.env') !== 'production') {
            return;
        }

        $token = $this->obtenerAccessToken();

        $verifyBody = [
            'transmission_id'   => $headers['paypal-transmission-id']   ?? $headers['PAYPAL-TRANSMISSION-ID']   ?? '',
            'transmission_time' => $headers['paypal-transmission-time']  ?? $headers['PAYPAL-TRANSMISSION-TIME']  ?? '',
            'cert_url'          => $headers['paypal-cert-url']           ?? $headers['PAYPAL-CERT-URL']           ?? '',
            'auth_algo'         => $headers['paypal-auth-algo']          ?? $headers['PAYPAL-AUTH-ALGO']          ?? '',
            'transmission_sig'  => $headers['paypal-transmission-sig']   ?? $headers['PAYPAL-TRANSMISSION-SIG']   ?? '',
            'webhook_id'        => $this->webhookId,
            'webhook_event'     => $payload,
        ];

        $response = $this->post('/v1/notifications/verify-webhook-signature', $verifyBody, $token);

        if (($response['verification_status'] ?? '') !== 'SUCCESS') {
            throw new PaymentWebhookException(self::NOMBRE, 'Firma del webhook PayPal no verificada.');
        }
    }

    // =========================================================================
    // EMITIR REEMBOLSO
    // =========================================================================

    public function emitirReembolso(string $gatewayPaymentId, float $monto, string $motivo): ReembolsoData
    {
        $token = $this->obtenerAccessToken();
        $body  = $monto > 0
            ? ['amount' => ['value' => number_format($monto, 2, '.', ''), 'currency_code' => 'MXN'], 'note_to_payer' => $motivo]
            : ['note_to_payer' => $motivo];

        $data = $this->post("/v2/payments/captures/{$gatewayPaymentId}/refund", $body, $token);

        return new ReembolsoData(
            gatewayRefundId: $data['id'] ?? '',
            monto:           (float) ($data['amount']['value'] ?? $monto),
            moneda:          $data['amount']['currency_code'] ?? 'MXN',
            status:          $data['status'] === 'COMPLETED' ? 'approved' : 'pending',
            raw:             $data,
        );
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    public function getNombre(): string
    {
        return self::NOMBRE;
    }

    private function obtenerAccessToken(): string
    {
        if ($this->cachedAccessToken) {
            return $this->cachedAccessToken;
        }

        $base = $this->sandbox ? self::SANDBOX_BASE : self::API_BASE;
        
        /** @var \Illuminate\Http\Client\Response $response */
        $response = Http::withBasicAuth($this->clientId, $this->clientSecret)
            ->asForm()
            ->timeout(10)
            ->post("{$base}/v1/oauth2/token", ['grant_type' => 'client_credentials']);

        if ($response->failed() || !isset($response->json()['access_token'])) {
            throw new PaymentGatewayException(self::NOMBRE, 'No se pudo obtener access token de PayPal.');
        }

        $this->cachedAccessToken = $response->json()['access_token'];

        return $this->cachedAccessToken;
    }

    private function apiBase(): string
    {
        return $this->sandbox ? self::SANDBOX_BASE : self::API_BASE;
    }

    private function get(string $endpoint, string $token): array
    {
        /** @var \Illuminate\Http\Client\Response $response */
        $response = Http::withToken($token)->acceptJson()->timeout(15)->get($this->apiBase() . $endpoint);
        return $this->handleResponse($response, 'GET', $endpoint);
    }

    private function post(string $endpoint, array $body, string $token): array
    {
        /** @var \Illuminate\Http\Client\Response $response */
        $response = Http::withToken($token)->acceptJson()->timeout(15)->post($this->apiBase() . $endpoint, $body);
        return $this->handleResponse($response, 'POST', $endpoint);
    }

    private function handleResponse(\Illuminate\Http\Client\Response $r, string $method, string $ep): array
    {
        if ($r->failed()) {
            $body = $r->json() ?? [];
            throw new PaymentGatewayException(self::NOMBRE, $body['message'] ?? "HTTP {$r->status()}", [
                'endpoint' => $ep, 'status' => $r->status(), 'body' => $body,
            ]);
        }

        return $r->json() ?? [];
    }
}
