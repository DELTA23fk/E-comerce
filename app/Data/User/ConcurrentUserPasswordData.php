<?php

namespace App\Data\User;

use Spatie\LaravelData\Data;

class ConcurrentUserPasswordData extends Data
{
    
    public function __construct(
        public string $adminPassword
    )
    {
        //
    }

        public static function messages(): array
        {
            return ['
                adminPassword.required' => 'El password del administrador es requerido'
            ];
        }
}
