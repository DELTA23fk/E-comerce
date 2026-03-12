<?php
namespace App\Data\Payment;

/**
 * Resultado normalizado de procesar un webhook entrante.
 */
readonly class WebhookResultData
{
    public function __construct(
        /**
         * Tipo de evento que trajo el webhook.
         * Valores posibles: 'payment' | 'refund' | 'chargeback' | 'other'
         */
        public string $tipoEvento,

        /** Estado normalizado resultante. */
        public string $status,

        /** ID del pago en el gateway (payment_id en MP, capture_id en PayPal). */
        public string $gatewayPaymentId,

        /** ID de la preferencia/orden original (preference_id en MP, order_id en PayPal). */
        public ?string $gatewayOrderId,

        /** Monto del evento (pago o reembolso). */
        public float $monto,

        public string $moneda,

        /** Payload raw para persistir en transacciones_pagos.response_raw */
        public array  $rawPayload  = [],

        /** Metadatos normalizados para transacciones_pagos.metadata */
        public array  $metadata    = [],
    ) {}

    public function esPago(): bool
    {
        return $this->tipoEvento === 'payment';
    }

    public function esReembolso(): bool
    {
        return $this->tipoEvento === 'refund';
    }

    public function aprobado(): bool
    {
        return $this->status === 'approved';
    }
}