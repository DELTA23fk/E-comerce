<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Data\User\AccessUserData;
use App\Data\User\RegisterUserData;
use App\Data\User\UpdateUserData;
use App\Enum\User\UserType;
use App\Http\Controllers\Controller;
use App\Services\AuthService;
use App\Services\TokenAuthService;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private readonly TokenAuthService $authService,
        private readonly AuthService $authServiceGeneral
    )
    {}

    public function register(RegisterUserData $request){

        $authResponse = $this->authService->register($request, UserType::CUSTOMER);

        return response()->json($authResponse, 201);
    }

    public function profile(Request $request){
        $user = $this->authServiceGeneral->profile();
        return response()->json($user, 200);
    }

    public function generateToken(AccessUserData $request){

        $authResponse = $this->authService->generateToken($request);

        return response()->json($authResponse, $authResponse->success ? 200 : 401);

    }

    public function revokeTokens(Request $request){
        $result = $this->authService->revokeTokens();
        return response()->json($result, 200);

    }

    public function updateProfile(UpdateUserData $request){
        $authResponse = $this->authService->updateProfile($request);
        return response()->json($authResponse, 200);
    }

}
