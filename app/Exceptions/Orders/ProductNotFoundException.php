<?php

namespace App\Exceptions\Orders;

use Exception;

class ProductNotFoundException extends OrderException
{
    protected int $statusCode = 404;
    protected string $errorCode = 'PRODUCT_NOT_FOUND';

    public function __construct(string $clave)
    {
        parent::__construct(
            "Producto con clave '{$clave}' no encontrado en el catálogo",
            ['clave' => $clave]
        );
    }
}