<?php

namespace App\Exceptions;

use Exception;
use RuntimeException;

/**
 * Error genérico al comunicarse con el gateway de pago.
 * Se lanza cuando la API del gateway devuelve un error o hay un problema de red.
 */
class PaymentGatewayException extends RuntimeException
{
    public function __construct(
        string               $gateway,
        string               $mensaje,
        public readonly ?array $context = null,
        ?\Throwable          $previous = null,
    ) {
        parent::__construct(
            "[{$gateway}] {$mensaje}",
            0,
            $previous,
        );
    }
}
