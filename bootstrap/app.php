<?php

use App\Exceptions\Cva\CvaApiException;
use App\Exceptions\Cva\CvaStockException;
use App\Exceptions\Cva\CvaTokenException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Exceptions\UnauthorizedException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (UnauthorizedException $e, Request $request) {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'No tienes permisos para acceder a este recurso.',
                'error' => 'Unauthorized'
            ], 403);
        }
        });
        // Manejo de CvaTokenException
        $exceptions->render(function (CvaTokenException $e, $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de autenticación con CVA. Intenta nuevamente.',
                    'data' => null,
                    'error_code' => 'CVA_TOKEN_ERROR'
                ], 401);
            }

            return back()->withErrors([
                'cva' => 'Error de autenticación con el proveedor.'
            ]);
        });

        // Manejo de CvaApiException
        $exceptions->render(function (CvaApiException $e, $request) {

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'data' => null,
                    'error_code' => 'CVA_API_ERROR'
                ], $e->getStatusCode() >= 500 ? 500 : 400);
            }

            return back()->withErrors([
                'cva' => $e->getMessage()
            ])->withInput();
        });
        $exceptions->render(function(CvaStockException $e, $request){
            if($request->expectsJson()){
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'data' => null,
                    'error_code' => 'CVA_STOCK_LOW'
                ],404);
            }
            return back()->withErrors([
                'cva' => $e->getMessage()
            ])->withInput();
        });
    })->create();
