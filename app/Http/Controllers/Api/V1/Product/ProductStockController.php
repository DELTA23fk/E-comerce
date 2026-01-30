<?php

namespace App\Http\Controllers\Api\V1\Product;

use App\Http\Controllers\Controller;
use App\Services\ProductResponseService;
use App\Services\ProductStockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductStockController extends Controller
{
    protected ProductStockService $stockService;
    protected ProductResponseService $responseService;

    public function __construct(
        ProductStockService $stockService,
        ProductResponseService $responseService
    ) {
        $this->stockService = $stockService;
        $this->responseService = $responseService;
    }

    /**
     * Productos con stock disponible
     */
    public function disponibles(Request $request): JsonResponse
    {
        try {
            $productos = $this->stockService->obtenerDisponibles($request);
            $response = $this->responseService->formatearListadoPaginado($productos, $request);
            return response()->json($response, 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Productos agotados
     */
    public function agotados(Request $request): JsonResponse
    {
        try {
            $productos = $this->stockService->obtenerAgotados($request);
            $response = $this->responseService->formatearListadoPaginado($productos, $request);
            return response()->json($response, 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Stock de un producto específico
     */
    public function stockPorProducto(int $productoId): JsonResponse
    {
        try {
            $stock = $this->stockService->obtenerStockPorProducto($productoId);
            
            return response()->json([
                'success' => true,
                'message' => 'Stock obtenido correctamente',
                'data' => $stock,
            ], 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Productos con stock bajo
     */
    public function stockBajo(Request $request): JsonResponse
    {
        try {
            $umbral = $request->input('umbral', 10);
            $productos = $this->stockService->obtenerStockBajo($umbral, $request);
            $response = $this->responseService->formatearListadoPaginado($productos, $request);
            return response()->json($response, 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Verificar disponibilidad de múltiples productos
     */
    public function verificarDisponibilidad(Request $request): JsonResponse
    {
        try {
            $productosIds = $request->input('productos', []);
            $disponibilidad = $this->stockService->verificarDisponibilidad($productosIds);
            
            return response()->json([
                'success' => true,
                'message' => 'Disponibilidad verificada correctamente',
                'data' => $disponibilidad,
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
