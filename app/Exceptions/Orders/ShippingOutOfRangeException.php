<?php

namespace App\Exceptions\Orders;

use Exception;

class ShippingOutOfRangeException extends ShippingException
{
    protected string $errorCode = 'SHIPPING_OUT_OF_RANGE';

    public function __construct(
        string $estado,
        string $ciudad,
        string $proveedorNombre = '',
        array $additionalContext = []
    ) {
        $message = "Envío no disponible para {$ciudad}, {$estado}";
        
        if ($proveedorNombre) {
            $message .= " con el proveedor {$proveedorNombre}";
        }

        $context = array_merge([
            'estado' => $estado,
            'ciudad' => $ciudad,
            'proveedor' => $proveedorNombre,
            'sugerencia' => 'Verifica que la dirección sea correcta o contacta a soporte'
        ], $additionalContext);

        parent::__construct($message, $context);
    }
}
