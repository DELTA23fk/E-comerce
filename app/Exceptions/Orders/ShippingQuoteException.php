<?php

namespace App\Exceptions\Orders;

use Exception;

class ShippingQuoteException extends ShippingException
{
    protected string $errorCode = 'SHIPPING_QUOTE_FAILED';

    public function __construct(
        string $proveedorNombre,
        string $reason = '',
        array $productos = []
    ) {
        $message = "No se pudo cotizar el envío con {$proveedorNombre}";
        
        if ($reason) {
            $message .= ": {$reason}";
        }

        parent::__construct($message, [
            'proveedor' => $proveedorNombre,
            'productos' => $productos,
            'razon' => $reason
        ]);
    }
}