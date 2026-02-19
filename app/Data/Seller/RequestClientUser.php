<?php

namespace App\Data\Seller;

use App\Data\Client\RequestClientBasic;
use App\Data\User\RegisterUserData;
use Spatie\LaravelData\Data;

class RequestClientUser extends Data
{
    public function __construct(
        public RegisterUserData $usuario,
        public RequestClientBasic $cliente
    ) {
        
    }
}
