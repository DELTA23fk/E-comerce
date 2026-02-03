<?php

namespace App\Data\User;

use Spatie\LaravelData\Attributes\Validation\Confirmed;
use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Password;
use Spatie\LaravelData\Attributes\Validation\Sometimes;
use Spatie\LaravelData\Attributes\Validation\Unique;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\References\RouteParameterReference;

class UpdateCustomUserData extends Data
{
    /**
     * Clase para registro de usuario con tipo personalizado
     */
    public function __construct(
        #[Sometimes,Max(230)]
        public string $nombre,
        #[Sometimes,Email(),Max(255),Unique('users','email',ignore:new RouteParameterReference('usuarioId'))]
        public string $correo,
        #[Sometimes,Password(min: 8, letters: true, mixedCase: true, numbers: true, symbols: true, uncompromised: true, uncompromisedThreshold: 0), Confirmed]
        public string $password,
        //password admin confirmation
        public string $adminPassword
        
    )
    {
        //
    }

    public static function messages(): array
    {
        return [
            'nombre.max' => 'El campo nombre no debe ser mayor a :max caracteres.',
            'nombre.required' => 'El nombre es requerido',
            'correo.email' => 'El campo correo electrónico debe ser una dirección de correo electrónico válida.',
            'correo.max' => 'El campo correo electrónico no debe ser mayor a :max caracteres.',
            'correo.unique' => 'El correo electrónico ya está en uso por otro usuario.',
            'correo.required' => 'El correo es requerido',
            'password.password' => 'La contraseña no cumple con los requisitos de seguridad.',
            'password.required' => 'El password es requerido',
            'password.confirmed' => 'La confirmación de la contraseña no coincide.',
            'adminPassword.required' => 'La contrasena del administrador es requerido',
            'rol.required' => 'El rol es requerido'
        ];
    }
}
