<?php

namespace App\Exceptions;

use Exception;
use RuntimeException;

/**
 * Webhook inválido: firma incorrecta, payload malformado o evento desconocido.
 */
class PaymentWebhookException extends RuntimeException
{
    public function __construct(
        string      $gateway,
        string      $razon,
        ?\Throwable $previous = null,
    ) {
        parent::__construct("[Webhook/{$gateway}] {$razon}", 0, $previous);
    }
}
