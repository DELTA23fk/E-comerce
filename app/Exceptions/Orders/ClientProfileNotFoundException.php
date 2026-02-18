<?php

namespace App\Exceptions\Orders;

use Exception;

class ClientProfileNotFoundException extends OrderException
{
    protected int $statusCode = 404;
    protected string $errorCode = 'CLIENT_PROFILE_NOT_FOUND';

    public function __construct(int $userId)
    {
        parent::__construct(
            "El usuario no tiene un perfil de cliente asociado",
            ['user_id' => $userId]
        );
    }
}
