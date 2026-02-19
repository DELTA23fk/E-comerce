<?php

namespace App\Services;

use App\Data\User\RegisterUserData;
use App\Data\User\UpdateCustomUserData;
use App\Data\User\UpdateUserData;
use App\Data\User\UserCustomData;
use App\Data\User\UserData;
use App\Enum\User\UserType;
use App\Models\User;
use App\Data\Client\RequestClientBasic;
class UserService
{
    public function getUser(User $user): UserData
    {
        return UserData::fromModel($user);
    }

    /**
     * Crear usuario con tipo específico
     */
    public function create( RegisterUserData|UserCustomData |RequestClientBasic $data, ?UserType $type = null): User
    {
        // Si es UserCustomData, usa su tipo
        // Si es RegisterUserData, usa el tipo pasado o CUSTOMER por defecto
        $userType = $data instanceof UserCustomData 
            ? $data->rol 
            : ($type ?? UserType::CUSTOMER);

        return User::create([
            'name' => $data->nombre ?? $data->name,
            'email' => $data->correo ?? $data->email,
            'password' => $data->password,
            'tipo' => $userType->value,
        ]);
    }
    public function update(User $user, UpdateUserData | UpdateCustomUserData $data): User
    {
        $dataFiltered = array_filter([
            'name' => $data->name ?? $data->nombre,
            'email' => $data->email ?? $data->correo,
            'password' => $data->password,
        ], fn($value) => $value !== null);

        $user->update($dataFiltered);
        $user->refresh();
        

        return $user;
    }       

    public function findById(int $id): ?User
    {
        return User::find($id);
    }

    public function findByEmail(string $email): ?User
    {
        return User::where('email', $email)->first();
    }
}