<?php

namespace App\Data\User;

use App\Enum\User\UserType;
use Spatie\LaravelData\Attributes\Validation\Enum;
use Spatie\LaravelData\Data;

class AssignetRoleUserData extends Data
{

    public function __construct(
        #[Enum(UserType::class)]
        public UserType $tipoUsuario,
        public string $adminPassword
    )
    {
        //
    }

        public static function messages(): array
        {
            return [
                'tipoUsuario.required' => 'El tipo de usuario(rol) es requerido',
                'adminPassword.required' => 'El password del administrador es requerido'
            ];
        }
}
