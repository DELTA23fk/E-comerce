<?php

use App\Http\Controllers\Api\V1\Auth\AuthController as ApiAuthController;
use App\Http\Controllers\Spa\Auth\AuthController as SpaAuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    //public routes here ------------------------
    //token
    Route::post('/auth/register', [ApiAuthController::class, 'register'])->name('auth.register')->middleware('guest');
    Route::post('/auth/token', [ApiAuthController::class, 'generateToken'])->name('auth.token')->middleware('guest');

    //cookie
    Route::prefix('spa')->group(function () {
        Route::post('/auth/register', [SpaAuthController::class, 'register'])->name('spa.auth.register')->middleware('guest');
        Route::post('/auth/login', [SpaAuthController::class, 'login'])->name('spa.auth.login')->middleware('guest');

    });
    
    //protected routes here
    Route::middleware('auth:sanctum')->group(function () {
        //API Routes
        Route::get('/auth/profile', [ApiAuthController::class, 'profile'])->name('auth.profile');
        Route::post('/auth/revoke-tokens', [ApiAuthController::class, 'revokeTokens'])->name('auth.revoke.tokens');
        Route::put('/auth/profile/update', [ApiAuthController::class, 'updateProfile'])->name('auth.profile.update');

        //SPA Routes - COOKIES
        Route::prefix('spa')->group(function () {
            Route::post('/auth/logout', [SpaAuthController::class, 'logout'])->name('spa.auth.logout');
            Route::put('/auth/profile/update', [SpaAuthController::class, 'updateProfile'])->name('spa.auth.profile.update');
        });
    });
});

