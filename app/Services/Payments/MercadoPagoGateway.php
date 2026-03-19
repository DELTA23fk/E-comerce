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
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Client\Payment\PaymentRefundClient;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\Exceptions\MPApiException;
use MercadoPago\MercadoPagoConfig;
use Illuminate\Support\Facades\Log;

/**
 * Implementación del Strategy para MercadoPago usando el SDK oficial dx-php v3.
 *
 * composer require mercadopago/dx-php
 *
 * Config mínima necesaria (.env):
 *   MP_ACCESS_TOKEN=APP_USR-xxxx   (obligatorio)
 *   MP_SANDBOX=true                (opcional, default true fuera de production)
 *   MP_WEBHOOK_SECRET=xxxx         (opcional, requerido en producción)
 *
 * El SDK gestiona internamente la autenticación y los endpoints (sandbox/prod).
 * La validación HMAC del webhook sigue siendo manual — el SDK no la implementa.
 */
class MercadoPagoGateway implements PaymentGatewayInterface
{
    private const NOMBRE = 'mercadopago';

    private const STATUS_MAP = [
        'pending'      => 'pending',
        'approved'     => 'approved',
        'authorized'   => 'pending',
        'in_process'   => 'pending',
        'in_mediation' => 'in_mediation',
        'rejected'     => 'rejected',
        'cancelled'    => 'cancelled',
        'refunded'     => 'refunded',
        'charged_back' => 'charged_back',
    ];

    public function __construct(
        private readonly string  $accessToken,
        private readonly bool    $sandbox        = true,
        private readonly ?string $webhookSecret  = null,
    ) {
        // El SDK usa configuración global por proceso.
        // Al ser este gateway singleton-efectivo (inyectado en factory singleton),
        // esto se ejecuta una sola vez por ciclo de vida del proceso.
        MercadoPagoConfig::setAccessToken($this->accessToken);

        MercadoPagoConfig::setRuntimeEnviroment(
            $this->sandbox
                ? MercadoPagoConfig::LOCAL   // sandbox
                : MercadoPagoConfig::SERVER  // producción
        );
    }

    // =========================================================================
    // CREAR PREFERENCIA
    // =========================================================================

    public function crearPreferencia(Pedido $pedido): PreferenceResponseData
    {
        Log::info('[MercadoPago] Creando preferencia', [
            'pedido_id' => $pedido->id,
            'folio'     => $pedido->folio,
            'total'     => $pedido->precio_total,
        ]);

        try {
            $client     = new PreferenceClient();
            $preference = $client->create($this->buildPreferencePayload($pedido));

        } catch (MPApiException $e) {
            throw new PaymentGatewayException(
                self::NOMBRE,
                "Error al crear preferencia: {$e->getMessage()}",
                [
                    'status'  => $e->getApiResponse()?->getStatusCode(),
                    'content' => $e->getApiResponse()?->getContent(),
                ],
                $e,
            );
        }

        return new PreferenceResponseData(
            gatewayOrderId: $preference->id,
            redirectUrl:    $preference->init_point,
            sandboxUrl:     $preference->sandbox_init_point ?? null,
            extra: [
                'collector_id'       => $preference->collector_id       ?? null,
                'client_id'          => $preference->client_id          ?? null,
                'date_created'       => $preference->date_created       ?? null,
                'date_of_expiration' => $preference->date_of_expiration ?? null,
            ],
        );
    }

    private function buildPreferencePayload(Pedido $pedido): array
    {
        $cliente = $pedido->cliente;

        $items = $pedido->detalles->map(fn($d) => [
            'id'          => (string) $d->proveedor_producto_id,
            'title'       => $d->producto?->nombre ?? "Producto {$d->clave_proveedor}",
            'description' => $d->clave_proveedor,
            'quantity'    => $d->cantidad,
            'unit_price'  => (float) $d->precio_unitario,
            'currency_id' => 'MXN',
        ])->toArray();

        if ((float) $pedido->precio_total_envio > 0) {
            $items[] = [
                'id'          => 'ENVIO',
                'title'       => 'Costo de envío',
                'quantity'    => 1,
                'unit_price'  => (float) $pedido->precio_total_envio,
                'currency_id' => 'MXN',
            ];
        }

        $baseUrl = config('app.url');

        return [
            'items'  => $items,
            'payer'  => [
                'name'    => $cliente->nombre   ?? '',
                'surname' => $cliente->apellido ?? '',
                'email'   => $cliente->user?->email ?? '',
                'phone'   => ['number' => $cliente->telefono ?? ''],
                'address' => [
                    'zip_code'      => $pedido->datos_envio['codigo_postal'] ?? '',
                    'street_name'   => $pedido->datos_envio['calle']         ?? '',
                    'street_number' => $pedido->datos_envio['numero']        ?? '',
                ],
            ],
            'back_urls' => [
                'success' => "{$baseUrl}/api/v1/pedidos/pagos/resultado?status=success&folio={$pedido->folio}",
                'failure' => "{$baseUrl}/api/v1/pedidos/pagos/resultado?status=failure&folio={$pedido->folio}",
                'pending' => "{$baseUrl}/api/v1/pedidos/pagos/resultado?status=pending&folio={$pedido->folio}",
            ],
            'auto_return'          => 'approved',
            'notification_url'     => "{$baseUrl}/webhooks/mercadopago",
            'external_reference'   => $pedido->folio,
            'statement_descriptor' => config('app.name', 'Todo para oficinas'),
            'expires'              => true,
            'expiration_date_to'   => now()->addHours(24)->toIso8601String(),
            'metadata' => [
                'pedido_id'  => $pedido->id,
                'cliente_id' => $pedido->cliente_id,
            ],
        ];
    }

    // =========================================================================
    // VERIFICAR PAGO
    // =========================================================================

    public function verificarPago(string $gatewayPaymentId): PaymentStatusData
    {
        try {
            $client  = new PaymentClient();
            $payment = $client->get((int) $gatewayPaymentId);

        } catch (MPApiException $e) {
            throw new PaymentGatewayException(
                self::NOMBRE,
                "Error al verificar pago {$gatewayPaymentId}: {$e->getMessage()}",
                ['status' => $e->getApiResponse()?->getStatusCode()],
                $e,
            );
        }

        return $this->normalizarPayment($payment);
    }

    // =========================================================================
    // PROCESAR WEBHOOK
    // =========================================================================

    public function procesarWebhook(array $payload, array $headers): WebhookResultData
    {
        $this->validarFirmaWebhook($payload, $headers);

        $tipo = $payload['type'] ?? $payload['topic'] ?? '';

        if (!in_array($tipo, ['payment', 'refund'], true)) {
            Log::info('[MercadoPago] Webhook ignorado — tipo no relevante', ['type' => $tipo]);

            return new WebhookResultData(
                tipoEvento:       'other',
                status:           'ignored',
                gatewayPaymentId: '',
                gatewayOrderId:   null,
                monto:            0,
                moneda:           'MXN',
                rawPayload:       $payload,
            );
        }

        $paymentId = (string) ($payload['data']['id'] ?? $payload['id'] ?? '');

        if (empty($paymentId)) {
            throw new PaymentWebhookException(self::NOMBRE, 'No se encontró data.id en el payload.');
        }

        // Consultar estado real con el SDK — no confiar solo en el payload del webhook
        try {
            $client  = new PaymentClient();
            $payment = $client->get((int) $paymentId);

        } catch (MPApiException $e) {
            throw new PaymentGatewayException(
                self::NOMBRE,
                "No se pudo consultar pago {$paymentId}: {$e->getMessage()}",
                ['status' => $e->getApiResponse()?->getStatusCode()],
                $e,
            );
        }

        $statusData = $this->normalizarPayment($payment);

        return new WebhookResultData(
            tipoEvento:       empty($payment->refunds) ? 'payment' : 'refund',
            status:           $statusData->status,
            gatewayPaymentId: $statusData->gatewayPaymentId,
            gatewayOrderId:   $statusData->gatewayOrderId,
            monto:            $statusData->monto,
            moneda:           $statusData->moneda,
            rawPayload:       json_decode(json_encode($payment), true) ?? [],
            metadata:         $statusData->meta,
        );
    }

    private function validarFirmaWebhook(array $payload, array $headers): void
    {
        // Sin secret configurado — omitir en sandbox, advertir en producción
        if (empty($this->webhookSecret)) {
            if (!$this->sandbox) {
                Log::warning('[MercadoPago] MP_WEBHOOK_SECRET no configurado en producción.');
            }
            return;
        }

        $xSignature = $headers['x-signature'] ?? $headers['X-Signature'] ?? null;
        $xRequestId = $headers['x-request-id'] ?? $headers['X-Request-Id'] ?? null;

        if (!$xSignature) {
            throw new PaymentWebhookException(self::NOMBRE, 'Header x-signature ausente.');
        }

        $parts = [];
        foreach (explode(',', $xSignature) as $part) {
            [$key, $value] = explode('=', $part, 2) + [null, null];
            if ($key && $value) {
                $parts[trim($key)] = trim($value);
            }
        }

        $ts = $parts['ts'] ?? null;
        $v1 = $parts['v1'] ?? null;

        if (!$ts || !$v1) {
            throw new PaymentWebhookException(self::NOMBRE, 'x-signature mal formateado.');
        }

        $manifest = "id:{$payload['data']['id']};request-id:{$xRequestId};ts:{$ts};";

        if (!hash_equals(hash_hmac('sha256', $manifest, $this->webhookSecret), $v1)) {
            throw new PaymentWebhookException(self::NOMBRE, 'Firma del webhook no coincide.');
        }
    }

    // =========================================================================
    // EMITIR REEMBOLSO
    // =========================================================================

    public function emitirReembolso(string $gatewayPaymentId, float $monto, string $motivo): ReembolsoData
    {
        Log::info('[MercadoPago] Emitiendo reembolso', [
            'payment_id' => $gatewayPaymentId,
            'monto'      => $monto,
        ]);

        try {
            $client = new PaymentRefundClient();

            // El SDK separa explícitamente reembolso parcial y total:
            // refund(int $id, float $amount) → parcial
            // refundTotal(int $id)           → total (sin monto)
            $refund = $monto > 0
                ? $client->refund((int) $gatewayPaymentId, $monto)
                : $client->refundTotal((int) $gatewayPaymentId);

        } catch (MPApiException $e) {
            throw new PaymentGatewayException(
                self::NOMBRE,
                "Error al emitir reembolso: {$e->getMessage()}",
                ['status' => $e->getApiResponse()?->getStatusCode()],
                $e,
            );
        }

        return new ReembolsoData(
            gatewayRefundId: (string) ($refund->id     ?? ''),
            monto:           (float)  ($refund->amount ?? $monto),
            moneda:          $refund->currency_id      ?? 'MXN',
            status:          ($refund->status ?? '') === 'approved' ? 'approved' : 'pending',
            raw:             json_decode(json_encode($refund), true) ?? [],
        );
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    public function getNombre(): string
    {
        return self::NOMBRE;
    }

    /**
     * Normaliza el objeto Payment del SDK (propiedades públicas) al DTO interno.
     */
    private function normalizarPayment(object $payment): PaymentStatusData
    {
        $statusRaw  = $payment->status ?? 'unknown';
        $statusNorm = self::STATUS_MAP[$statusRaw] ?? 'pending';

        return new PaymentStatusData(
            gatewayPaymentId: (string) ($payment->id ?? ''),
            status:           $statusNorm,
            statusRaw:        $statusRaw,
            monto:            (float) ($payment->transaction_amount ?? 0),
            moneda:           $payment->currency_id  ?? 'MXN',
            gatewayOrderId:   $payment->preference_id ?? null,
            meta: [
                'payment_method_id'  => $payment->payment_method_id        ?? null,
                'payment_type_id'    => $payment->payment_type_id          ?? null,
                'installments'       => $payment->installments             ?? null,
                'issuer_id'          => $payment->issuer_id                ?? null,
                'last_four_digits'   => $payment->card->last_four_digits   ?? null,
                'payer_email'        => $payment->payer->email             ?? null,
                'external_reference' => $payment->external_reference       ?? null,
                'merchant_order_id'  => $payment->order->id                ?? null,
                'status_detail'      => $payment->status_detail            ?? null,
                'fee_amount'         => collect($payment->fee_details ?? [])->sum('amount'),
            ],
        );
    }
}