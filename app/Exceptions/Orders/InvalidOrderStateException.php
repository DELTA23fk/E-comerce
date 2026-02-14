<?php

namespace App\Exceptions\Orders;

use Exception;

class InvalidOrderStateException extends OrderException
{
    protected int $statusCode = 409;
    protected string $errorCode = 'INVALID_ORDER_STATE';

    public function __construct(
        int $pedidoId,
        string $estatusActual,
        string $estatusEsperado
    ) {
        parent::__construct(
            "El pedido #{$pedidoId} no puede procesarse en estado '{$estatusActual}'",
            [
                'pedido_id' => $pedidoId,
                'estatus_actual' => $estatusActual,
                'estatus_esperado' => $estatusEsperado
            ]
        );
    }
}
