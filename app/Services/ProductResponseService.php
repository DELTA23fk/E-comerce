<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class ProductResponseService
{
    /**
     * Formatear respuesta de listado paginado
     * 
     * @param LengthAwarePaginator $productos
     * @param Request $request
     * @return array
     */
    public function formatearListadoPaginado(LengthAwarePaginator $productos, Request $request): array
    {
        return [
            'success' => true,
            'message' => 'Productos obtenidos correctamente',
            'data' => $productos->items(),
            'pagination' => $this->formatearPaginacion($productos),
            'filters_applied' => $this->getFiltrosAplicados($request),
        ];
    }

    /**
     * Formatear respuesta de producto único
     * 
     * @param mixed $producto
     * @return array
     */
    public function formatearProductoUnico($producto): array
    {
        if (!$producto) {
            return [
                'success' => false,
                'message' => 'Producto no encontrado',
            ];
        }

        return [
            'success' => true,
            'message' => 'Producto obtenido correctamente',
            'data' => $producto,
        ];
    }

    /**
     * Formatear información de paginación
     * 
     * @param LengthAwarePaginator $paginator
     * @return array
     */
    private function formatearPaginacion(LengthAwarePaginator $paginator): array
    {
        return [
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }

    /**
     * Obtener filtros aplicados (excluyendo parámetros de paginación)
     * 
     * @param Request $request
     * @return array
     */
    private function getFiltrosAplicados(Request $request): array
    {
        return $request->except(['page', 'per_page']);
    }

    /**
     * Formatear respuesta de error
     * 
     * @param string $message
     * @param int $statusCode
     * @return array
     */
    public function formatearError(string $message, int $statusCode = 400): array
    {
        return [
            'success' => false,
            'message' => $message,
            'status_code' => $statusCode,
        ];
    }
}
