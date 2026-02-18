<?php

namespace App\Exceptions\Orders;

use Exception;

class ShippingException extends OrderException
{
    protected int $statusCode = 422;
    protected string $errorCode = 'SHIPPING_ERROR';
}
