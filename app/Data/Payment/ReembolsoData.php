<?php
namespace App\Data\Payment;

readonly class ReembolsoData
{
    public function __construct(
        /** ID del reembolso en el gateway (refund_id). */
        public string  $gatewayRefundId,

        /** Monto efectivamente reembolsado. */
        public float   $monto,

        public string  $moneda,

        /** 'approved' | 'pending' | 'rejected' */
        public string  $status,

        /** Datos adicionales del gateway (para metadata). */
        public array   $raw = [],
    ) {}

    public function aprobado(): bool
    {
        return $this->status === 'approved';
    }
}