<?php

namespace App\Exceptions;

use Exception;
use RuntimeException;

/**
 * El pedido no está en el estado correcto para iniciar o confirmar un pago.
 */
class InvalidPaymentStateException extends RuntimeException
{
    public function __construct(
        int    $pedidoId,
        string $estadoActual,
        string $estadoEsperado,
    ) {
        parent::__construct(
            "Pedido #{$pedidoId}: estado de pago inválido. Actual: '{$estadoActual}', esperado: '{$estadoEsperado}'."
        );
    }
}