<?php

namespace App\Http\Controllers\Api\V1\Product;

use App\Http\Controllers\Controller;
use App\Services\ProductProviderService;
use App\Services\ProductResponseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductProviderController extends Controller
{
    protected ProductProviderService $proveedorService;
    protected ProductResponseService $responseService;

    public function __construct(
        ProductProviderService $proveedorService,
        ProductResponseService $responseService
    ) {
        $this->proveedorService = $proveedorService;
        $this->responseService = $responseService;
    }

    /**
     * Productos de un proveedor
     */
    public function porProveedor(Request $request, int $proveedorId): JsonResponse
    {
        try {
            $productos = $this->proveedorService->obtenerPorProveedor($proveedorId, $request);
            $response = $this->responseService->formatearListadoPaginado($productos, $request);
            return response()->json($response, 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Comparar precios entre proveedores
     */
    public function compararPrecios(int $productoId): JsonResponse
    {
        try {
            $comparacion = $this->proveedorService->compararPrecios($productoId);
            
            return response()->json([
                'success' => true,
                'message' => 'Comparación de precios obtenida correctamente',
                'data' => $comparacion,
            ], 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Mejor precio disponible
     */
    public function mejorPrecio(int $productoId): JsonResponse
    {
        try {
            $mejorPrecio = $this->proveedorService->obtenerMejorPrecio($productoId);
            
            return response()->json([
                'success' => true,
                'message' => 'Mejor precio obtenido correctamente',
                'data' => $mejorPrecio,
            ], 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Historial de precios
     */
    public function historialPrecios(Request $request, int $productoId): JsonResponse
    {
        try {
            $dias = $request->input('dias', 30);
            $historial = $this->proveedorService->obtenerHistorialPrecios($productoId, $dias);
            
            return response()->json([
                'success' => true,
                'message' => 'Historial de precios obtenido correctamente',
                'data' => $historial,
            ], 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Proveedores por producto
     */
    public function proveedoresPorProducto(int $productoId): JsonResponse
    {
        try {
            $proveedores = $this->proveedorService->obtenerProveedoresPorProducto($productoId);
            
            return response()->json([
                'success' => true,
                'message' => 'Proveedores obtenidos correctamente',
                'data' => $proveedores,
            ], 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    private function errorResponse(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Error: ' . $message,
        ], 500);
    }
}
