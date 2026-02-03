<?php

namespace App\Services\Users;

use App\Data\Response\ApiResponseData;
use App\Data\User\AssignetRoleUserData;
use App\Data\User\ConcurrentUserPasswordData;
use App\Data\User\UpdateCustomUserData;
use App\Data\User\UserCustomData;
use App\Data\User\UserData;
use App\Enum\User\UserRole;
use App\Enum\User\UserType;
use App\Services\UserService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class ManagerUsersService
{
    public function __construct(
        protected UserService $userService
    ) {}

    public function getTypesUser(): ApiResponseData
    {
        return new ApiResponseData(
            success: true,
            data: UserType::toArray()
        );
    }

    public function createUser(UserCustomData $data): ApiResponseData
    {
        // Validación consolidada de permisos y contraseña
        if ($error = $this->validateAdminAction($data->adminPassword)) {
            return $error;
        }

        try {
            return DB::transaction(function () use ($data) {
                $type = $this->validateUserType($data->rol);
                $user = $this->userService->create($data, $type);
                
                $user->assignRole($type->value);

                return new ApiResponseData(
                    success: true,
                    message: 'Usuario registrado correctamente',
                    data: UserData::from($user)
                );
            });
        } catch (\Exception $e) {
            return $this->errorResponse('Error al crear el usuario: ' . $e->getMessage());
        }
    }

    public function updateUser(UpdateCustomUserData $data, string|int $userId): ApiResponseData
    {
        // Validación consolidada
        if ($error = $this->validateAdminAction($data->adminPassword)) {
            return $error;
        }

        $user = $this->userService->findById($userId);
        
        if (!$user) {
            return $this->errorResponse('Usuario no encontrado');
        }

        try {
            $result = $this->userService->update($user, $data);
            
            return new ApiResponseData(
                success: true,
                message: 'Usuario actualizado correctamente',
                data: UserData::from($result)
            );
        } catch (\Exception $e) {
            return $this->errorResponse('Error al actualizar el usuario: ' . $e->getMessage());
        }
    }

    public function assignRoleToUser(AssignetRoleUserData $data, string|int $userId): ApiResponseData
    {
        // Validación consolidada
        if ($error = $this->validateAdminAction($data->adminPassword)) {
            return $error;
        }

        try {
            return DB::transaction(function () use ($data, $userId) {
                $user = $this->userService->findById($userId);
                
                if (!$user) {
                    return $this->errorResponse('Usuario no encontrado');
                }

                $role = $this->mapUserTypeToRole($data->tipoUsuario);

                if ($user->hasRole($role->value)) {
                    return $this->errorResponse("El usuario ya tiene asignado el rol: {$role->label()}");
                }

                $user->assignRole($role->value);
                $user->update(['tipo' => $data->tipoUsuario]);

                return new ApiResponseData(
                    success: true,
                    message: 'Rol asignado correctamente',
                    data: UserData::from($user->fresh())
                );
            });
        } catch (\Exception $e) {
            return $this->errorResponse('Error al asignar rol: ' . $e->getMessage());
        }
    }

    public function removeRoleFromUser(ConcurrentUserPasswordData $data, string|int $userId): ApiResponseData
    {
        // Validación consolidada
        if ($error = $this->validateAdminAction($data->adminPassword)) {
            return $error;
        }

        $user = $this->userService->findById($userId);

        if (!$user) {
            return $this->errorResponse('Usuario no encontrado');
        }

        // Prevenir auto-remoción de rol admin
        if (Auth::id() === $user->id) {
            return $this->errorResponse('No puedes quitarte tu propio rol de administrador');
        }

        try {
            return DB::transaction(function () use ($user) {
                $user->syncRoles([]); // Más eficiente que removeRole con array

                return new ApiResponseData(
                    success: true,
                    message: 'Roles removidos correctamente',
                    data: UserData::from($user->fresh())
                );
            });
        } catch (\Exception $e) {
            return $this->errorResponse('Error al remover roles: ' . $e->getMessage());
        }
    }

    // ========== MÉTODOS PRIVADOS DE UTILIDAD ==========

    /**
     * Valida que el usuario sea admin y que la contraseña sea correcta
     */
    private function validateAdminAction(string $password): ?ApiResponseData
    {
        $admin = Auth::user();

        if (!$admin->hasRole('admin')) {
            return $this->errorResponse('No tiene permisos para realizar esta acción');
        }

        if (!Hash::check($password, $admin->password)) {
            return $this->errorResponse('Contraseña de confirmación incorrecta');
        }

        return null;
    }

    /**
     * Valida y retorna el tipo de usuario
     */
    private function validateUserType(UserType $type): UserType
    {
        return match($type) {
            UserType::ADMIN, UserType::CUSTOMER, UserType::SELLER => $type,
            default => throw new \InvalidArgumentException("Tipo de usuario inválido")
        };
    }

    /**
     * Mapea UserType a UserRole
     */
    private function mapUserTypeToRole(UserType $type): UserRole
    {
        return match($type) {
            UserType::ADMIN => UserRole::ADMIN,
            UserType::CUSTOMER => UserRole::CUSTOMER,
            UserType::SELLER => UserRole::SELLER,
        };
    }

    /**
     * Crea una respuesta de error consistente
     */
    private function errorResponse(string $message): ApiResponseData
    {
        return new ApiResponseData(
            success: false,
            message: $message
        );
    }
}