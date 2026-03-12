<?php
namespace App\Data\Payment;

/**
 * Respuesta normalizada al crear una preferencia/orden de pago.
 */
readonly class PreferenceResponseData
{
    public function __construct(
        /** ID de la orden/preferencia creada en el gateway (antes del pago). */
        public string  $gatewayOrderId,

        /** URL a la que se debe redirigir al usuario para pagar. */
        public string  $redirectUrl,

        /** URL sandbox (solo MercadoPago). Null en PayPal. */
        public ?string $sandboxUrl = null,

        /** Datos adicionales específicos del gateway. */
        public array   $extra = [],
    ) {}
}