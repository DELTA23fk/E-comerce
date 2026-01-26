<?php

namespace App\Services;

use App\Enum\Permisos\PermissionEnum;
use App\Enum\User\UserRole;
use App\Models\User;

class PermissionService
{
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }

    public function setRole(User $user, UserRole $roleName): void
    {
        $user->assignRole($roleName);
    }

    public function removeRole(User $user, UserRole $roleName): void
    {
        $user->removeRole($roleName);
    }

    public function hasRole(User $user, UserRole $roleName): bool
    {
        return $user->hasRole($roleName);
    }

    public function hasPermission(User $user, PermissionEnum $permissionName): bool
    {
        return $user->can($permissionName);
    }

    public function givePermission(User $user, PermissionEnum $permissionName): void
    {
        $user->givePermissionTo($permissionName);
    }
}
