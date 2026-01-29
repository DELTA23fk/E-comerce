<?php

namespace App\Http\Controllers\Api\V1\Product;

use App\Http\Controllers\Controller;
use App\Services\ProductResponseService;
use App\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductoController extends Controller
{
    protected ProductService $productoService;
    protected ProductResponseService $responseService;

    public function __construct(
        ProductService $productoService,
        ProductResponseService $responseService
    ) {
        $this->productoService = $productoService;
        $this->responseService = $responseService;
    }

    /**
     * Listado general con filtros básicos
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $productos = $this->productoService->getProductosConFiltros($request);
            $response = $this->responseService->formatearListadoPaginado($productos, $request);
            return response()->json($response, 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Detalle de un producto
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $producto = $this->productoService->getProductoPorId($id, $request);
            $response = $this->responseService->formatearProductoUnico($producto);
            $statusCode = $response['success'] ? 200 : 404;
            return response()->json($response, $statusCode);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Producto por código de fabricante
     */
    public function porCodigo(Request $request, string $codigo): JsonResponse
    {
        try {
            $producto = $this->productoService->buscarPorCodigoFabricante($codigo, $request);
            $response = $this->responseService->formatearProductoUnico($producto);
            $statusCode = $response['success'] ? 200 : 404;
            return response()->json($response, $statusCode);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Producto por código de barras
     */
    public function porCodigoBarras(Request $request, string $codigoBarras): JsonResponse
    {
        try {
            $producto = $this->productoService->buscarPorCodigoBarras($codigoBarras, $request);
            $response = $this->responseService->formatearProductoUnico($producto);
            $statusCode = $response['success'] ? 200 : 404;
            return response()->json($response, $statusCode);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Producto por UPC
     */
    public function porUPC(Request $request, string $upc): JsonResponse
    {
        try {
            $producto = $this->productoService->buscarPorUPC($upc, $request);
            $response = $this->responseService->formatearProductoUnico($producto);
            $statusCode = $response['success'] ? 200 : 404;
            return response()->json($response, $statusCode);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Por rango de precios
     */
    public function porRangoPrecio(Request $request): JsonResponse
    {
        try {
            $productos = $this->productoService->buscarPorRangoPrecio(
                $request->input('min'),
                $request->input('max'),
                $request
            );
            $response = $this->responseService->formatearListadoPaginado($productos, $request);
            return response()->json($response, 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Productos recientes
     */
    public function recientes(Request $request): JsonResponse
    {
        try {
            $dias = $request->input('dias', 30);
            $productos = $this->productoService->obtenerRecientes($dias, $request);
            $response = $this->responseService->formatearListadoPaginado($productos, $request);
            return response()->json($response, 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Productos populares
     */
    public function populares(Request $request): JsonResponse
    {
        try {
            $limite = $request->input('limite', 10);
            $productos = $this->productoService->obtenerPopulares($limite, $request);
            return response()->json([
                'success' => true,
                'message' => 'Productos populares obtenidos correctamente',
                'data' => $productos,
            ], 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Productos destacados
     */
    public function destacados(Request $request): JsonResponse
    {
        try {
            $productos = $this->productoService->obtenerDestacados($request);
            $response = $this->responseService->formatearListadoPaginado($productos, $request);
            return response()->json($response, 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Imágenes de un producto
     */
    public function imagenes(int $productoId): JsonResponse
    {
        try {
            $imagenes = $this->productoService->obtenerImagenes($productoId);
            return response()->json([
                'success' => true,
                'message' => 'Imágenes obtenidas correctamente',
                'data' => $imagenes,
            ], 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Imagen principal de un producto
     */
    public function imagenPrincipal(int $productoId): JsonResponse
    {
        try {
            $imagen = $this->productoService->obtenerImagenPrincipal($productoId);
            return response()->json([
                'success' => true,
                'message' => 'Imagen principal obtenida correctamente',
                'data' => $imagen,
            ], 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Resumen de estadísticas
     */
    public function resumenEstadisticas(): JsonResponse
    {
        try {
            $estadisticas = $this->productoService->obtenerResumenEstadisticas();
            return response()->json([
                'success' => true,
                'message' => 'Estadísticas obtenidas correctamente',
                'data' => $estadisticas,
            ], 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Conteo por categoría
     */
    public function conteoPorCategoria(): JsonResponse
    {
        try {
            $conteo = $this->productoService->contarPorCategoria();
            return response()->json([
                'success' => true,
                'message' => 'Conteo por categoría obtenido correctamente',
                'data' => $conteo,
            ], 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Conteo por marca
     */
    public function conteoPorMarca(): JsonResponse
    {
        try {
            $conteo = $this->productoService->contarPorMarca();
            return response()->json([
                'success' => true,
                'message' => 'Conteo por marca obtenido correctamente',
                'data' => $conteo,
            ], 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage());
        }
    }

    /**
     * Método auxiliar para respuestas de error
     */
    private function errorResponse(string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Error: ' . $message,
        ], 500);
    }
}
