<?php 
namespace App\Data\Payment;

/**
 * Estado normalizado de un pago consultado al gateway.
 *
 * Los estados se mapean al vocabulario interno del sistema sin importar el gateway:
 *   pending | approved | rejected | cancelled | refunded | in_mediation | charged_back
 */
readonly class PaymentStatusData
{
    public function __construct(
        public string  $gatewayPaymentId,
        public string  $status,          // estado normalizado interno
        public string  $statusRaw,       // estado original del gateway (para auditoría)
        public float   $monto,
        public string  $moneda,
        public ?string $gatewayOrderId  = null,
        public array   $meta            = [],  // datos adicionales (método de pago, últimos 4, etc.)
    ) {
        //
    }

    public function aprobado(): bool
    {
        return $this->status === 'approved';
    }

    public function pendiente(): bool
    {
        return $this->status === 'pending';
    }

    public function rechazado(): bool
    {
        return in_array($this->status, ['rejected', 'cancelled'], true);
    }
}