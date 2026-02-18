<?php

namespace App\Exceptions\Orders;

use Exception;

class ProviderException extends OrderException
{
    protected int $statusCode = 502;
    protected string $errorCode = 'PROVIDER_ERROR';

    public function __construct(
        string $proveedorNombre,
        string $operation,
        string $reason,
        array $context = []
    ) {
        parent::__construct(
            "Error en {$operation} con {$proveedorNombre}: {$reason}",
            array_merge([
                'proveedor' => $proveedorNombre,
                'operacion' => $operation
            ], $context)
        );
    }
}