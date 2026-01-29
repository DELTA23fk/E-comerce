<?php

namespace App\Http\Controllers\Api\V1\Product;

use App\Http\Controllers\Controller;
use App\Services\ProductOfferService;
use App\Services\ProductResponseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductOfferController extends Controller
{
    protected ProductOfferService $ofertaService;
    protected ProductResponseService $responseService;

    public function __construct(
        ProductOfferService $ofertaService,
        ProductResponseService $responseService
    ) {
        $this->ofertaService = $ofertaService;
        $this->responseService = $responseService;
    }

    /**
     * Todos los productos en oferta
     */
    public function productosEnOferta(Request $request): JsonResponse
    {
        try {
            $productos = $this->ofertaService->obtenerProductosEnOferta($request);
            $response = $this->responseService->formatearListadoPaginado($productos, $request);
            return response()->json($response, 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Ofertas por categoría
     */
    public function ofertasPorCategoria(Request $request, int $categoriaId): JsonResponse
    {
        try {
            $productos = $this->ofertaService->obtenerOfertasPorCategoria($categoriaId, $request);
            $response = $this->responseService->formatearListadoPaginado($productos, $request);
            return response()->json($response, 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Promociones activas
     */
    public function promocionesActivas(Request $request): JsonResponse
    {
        try {
            $promociones = $this->ofertaService->obtenerPromocionesActivas($request);
            $response = $this->responseService->formatearListadoPaginado($promociones, $request);
            return response()->json($response, 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Descuentos mayores a un porcentaje
     */
    public function descuentosMayoresA(Request $request, int $porcentaje): JsonResponse
    {
        try {
            $productos = $this->ofertaService->obtenerDescuentosMayoresA($porcentaje, $request);
            $response = $this->responseService->formatearListadoPaginado($productos, $request);
            return response()->json($response, 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Ofertas del día
     */
    public function ofertasDelDia(Request $request): JsonResponse
    {
        try {
            $productos = $this->ofertaService->obtenerOfertasDelDia($request);
            $response = $this->responseService->formatearListadoPaginado($productos, $request);
            return response()->json($response, 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Ofertas próximas a vencer
     */
    public function ofertasPorVencer(Request $request): JsonResponse
    {
        try {
            $dias = $request->input('dias', 7);
            $productos = $this->ofertaService->obtenerOfertasPorVencer($dias, $request);
            $response = $this->responseService->formatearListadoPaginado($productos, $request);
            return response()->json($response, 200);
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
