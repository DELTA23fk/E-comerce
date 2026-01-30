<?php

namespace App\Http\Controllers\Api\V1\Product;

use App\Http\Controllers\Controller;
use App\Services\ProductCatalogService;
use App\Services\ProductResponseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductCatalogController extends Controller
{
    protected ProductCatalogService $catalogoService;
    protected ProductResponseService $responseService;

    public function __construct(
        ProductCatalogService $catalogoService,
        ProductResponseService $responseService
    ) {
        $this->catalogoService = $catalogoService;
        $this->responseService = $responseService;
    }

    /**
     * Productos por categoría
     */
    public function porCategoria(Request $request, int $categoriaId): JsonResponse
    {
        try {
            $productos = $this->catalogoService->obtenerPorCategoria($categoriaId, $request);
            $response = $this->responseService->formatearListadoPaginado($productos, $request);
            return response()->json($response, 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Productos por sub-categoría
     */
    public function porSubCategoria(Request $request, int $subCategoriaId): JsonResponse
    {
        try {
            $productos = $this->catalogoService->obtenerPorSubCategoria($subCategoriaId, $request);
            $response = $this->responseService->formatearListadoPaginado($productos, $request);
            return response()->json($response, 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Productos por familia
     */
    public function porFamilia(Request $request, int $familiaId): JsonResponse
    {
        try {
            $productos = $this->catalogoService->obtenerPorFamilia($familiaId, $request);
            $response = $this->responseService->formatearListadoPaginado($productos, $request);
            return response()->json($response, 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Productos por grupo
     */
    public function porGrupo(Request $request, int $grupoId): JsonResponse
    {
        try {
            $productos = $this->catalogoService->obtenerPorGrupo($grupoId, $request);
            $response = $this->responseService->formatearListadoPaginado($productos, $request);
            return response()->json($response, 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Productos por marca
     */
    public function porMarca(Request $request, int $marcaId): JsonResponse
    {
        try {
            $productos = $this->catalogoService->obtenerPorMarca($marcaId, $request);
            $response = $this->responseService->formatearListadoPaginado($productos, $request);
            return response()->json($response, 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Productos relacionados
     */
    public function productosRelacionados(Request $request, int $id): JsonResponse
    {
        try {
            $limite = $request->input('limite', 10);
            $productos = $this->catalogoService->obtenerRelacionados($id, $limite, $request);
            
            return response()->json([
                'success' => true,
                'message' => 'Productos relacionados obtenidos correctamente',
                'data' => $productos,
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
