<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Data\User\AssignetRoleUserData;
use App\Data\User\ConcurrentUserPasswordData;
use App\Data\User\UpdateCustomUserData;
use App\Data\User\UserCustomData;
use App\Http\Controllers\Controller;
use App\Services\Users\ManagerUsersService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ManagerUserController extends Controller
{
    public function __construct(
        protected ManagerUsersService $managerUsersService
    ) {}

    public function getTypesUser(): JsonResponse
    {
        $result = $this->managerUsersService->getTypesUser();
        
        return response()->json(
            data: $result,
            status: Response::HTTP_OK
        );
    }

    public function registerUserCustom(UserCustomData $data): JsonResponse
    {
        $result = $this->managerUsersService->createUser($data);
        
        return response()->json(
            data: $result,
            status: $result->success ? Response::HTTP_CREATED : Response::HTTP_BAD_REQUEST
        );
    }

    public function updateUser(UpdateCustomUserData $data, string|int $userId): JsonResponse
    {
        $result = $this->managerUsersService->updateUser($data, $userId);
        
        return response()->json(
            data: $result,
            status: $result->success ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST
        );
    }

    public function setRole(AssignetRoleUserData $data, string|int $userId): JsonResponse
    {
        $result = $this->managerUsersService->assignRoleToUser($data, $userId);
        
        return response()->json(
            data: $result,
            status: $result->success ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST
        );
    }

    public function removeRole(ConcurrentUserPasswordData $data, string|int $userId): JsonResponse
    {
        $result = $this->managerUsersService->removeRoleFromUser($data, $userId);
        
        return response()->json(
            data: $result,
            status: $result->success ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST
        );
    }
}