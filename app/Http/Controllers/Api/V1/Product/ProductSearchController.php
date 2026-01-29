<?php

namespace App\Http\Controllers\Api\V1\Product;

use App\Http\Controllers\Controller;
use App\Services\ProductResponseService;
use App\Services\ProductSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductSearchController extends Controller
{
    protected ProductSearchService $busquedaService;
    protected ProductResponseService $responseService;

    public function __construct(
        ProductSearchService $busquedaService,
        ProductResponseService $responseService
    ) {
        $this->busquedaService = $busquedaService;
        $this->responseService = $responseService;
    }

    /**
     * Búsqueda general
     */
    public function busquedaGeneral(Request $request): JsonResponse
    {
        try {
            $query = $request->input('q');
            $productos = $this->busquedaService->busquedaGeneral($query, $request);
            $response = $this->responseService->formatearListadoPaginado($productos, $request);
            return response()->json($response, 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Búsqueda por texto
     */
    public function porTexto(Request $request): JsonResponse
    {
        try {
            $texto = $request->input('texto');
            $productos = $this->busquedaService->buscarPorTexto($texto, $request);
            $response = $this->responseService->formatearListadoPaginado($productos, $request);
            return response()->json($response, 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Búsqueda avanzada
     */
    public function busquedaAvanzada(Request $request): JsonResponse
    {
        try {
            $criterios = $request->all();
            $productos = $this->busquedaService->busquedaAvanzada($criterios);
            $response = $this->responseService->formatearListadoPaginado($productos, $request);
            return response()->json($response, 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Sugerencias de búsqueda
     */
    public function sugerencias(Request $request): JsonResponse
    {
        try {
            $termino = $request->input('termino');
            $limite = $request->input('limite', 10);
            $sugerencias = $this->busquedaService->obtenerSugerencias($termino, $limite);
            
            return response()->json([
                'success' => true,
                'message' => 'Sugerencias obtenidas correctamente',
                'data' => $sugerencias,
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
