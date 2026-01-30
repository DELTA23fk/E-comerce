<?php

namespace App\Services;

use App\Models\Producto;
use App\Models\ProveedorProducto;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class ProductProviderService
{
    protected ProductRelationService $relationService;
    protected ProductRealtimeUpdateService $realtimeUpdateService;

    public function __construct(ProductRelationService $relationService, ProductRealtimeUpdateService $realtimeUpdateService
)
    {
        $this->relationService = $relationService;
        $this->realtimeUpdateService = $realtimeUpdateService;

    }

    /**
     * Obtener productos de un proveedor específico
     * 
     * @param int $proveedorId
     * @param Request $request
     * @return LengthAwarePaginator
     */
    public function obtenerPorProveedor(int $proveedorId, Request $request): LengthAwarePaginator
    {
        $builder = Producto::whereHas('proveedorProductos', function ($q) use ($proveedorId) {
            $q->where('proveedor_id', $proveedorId);
        });

        // Aplicar relaciones
        $relations = $this->relationService->buildRelations($request);
        if (!empty($relations)) {
            $builder->with($relations);
        }

        // Siempre incluir la relación del proveedor específico
        $builder->with(['proveedorProductos' => function ($q) use ($proveedorId) {
            $q->where('proveedor_id', $proveedorId)
                ->with(['proveedor', 'pricios', 'promociones']);
        }]);

        $perPage = min(max((int)$request->get('per_page', 15), 1), 100);
        
        return $builder->paginate($perPage);
    }

    /**
     * Comparar precios del producto entre proveedores
     * 
     * @param int $productoId
     * @return Collection
     */
    public function compararPrecios(int $productoId): Collection
    {
        $this->realtimeUpdateService->actualizarTodosLosProveedores($productoId);

        $producto = Producto::with([
            'proveedorProductos.proveedor',
            'proveedorProductos.pricios' => function ($q) {
                $q->latest('ultima_actualizacion')->limit(1);
            }
        ])->findOrFail($productoId);

        return $producto->proveedorProductos->map(function ($proveedorProducto) {
            $precioActual = $proveedorProducto->pricios->first();
            
            return [
                'proveedor_id' => $proveedorProducto->proveedor_id,
                'proveedor_nombre' => $proveedorProducto->proveedor->nombre,
                'codigo_proveedor' => $proveedorProducto->codigo_proveedor,
                'precio_actual' => $precioActual ? $precioActual->precio_actual : null,
                'precio_anterior' => $precioActual ? $precioActual->precio_anterior : null,
                'moneda' => $proveedorProducto->moneda,
                'stock' => $proveedorProducto->stock,
                'stock_cd' => $proveedorProducto->stock_cd,
                'en_oferta' => $proveedorProducto->en_oferta,
                'ultima_actualizacion' => $proveedorProducto->ultima_actualizacion,
            ];
        })->sortBy('precio_actual')->values();
    }

    /**
     * Obtener el mejor precio disponible
     * 
     * @param int $productoId
     * @return array|null
     */
    public function obtenerMejorPrecio(int $productoId): ?array
    {
        $this->realtimeUpdateService->actualizarTodosLosProveedores($productoId);

        $comparacion = $this->compararPrecios($productoId);
        
        // Filtrar solo proveedores con stock
        $conStock = $comparacion->filter(function ($item) {
            return $item['stock'] > 0 || $item['stock_cd'] > 0 && $item['precio_actual'] !== null;
        });

        if ($conStock->isEmpty()) {
            return null;
        }

        return $conStock->first();
    }

    /**
     * Obtener historial de precios
     * 
     * @param int $productoId
     * @param int $dias
     * @return Collection
     */
    public function obtenerHistorialPrecios(int $productoId, int $dias = 30): Collection
    {
        $fechaInicio = now()->subDays($dias);

        $producto = Producto::with([
            'proveedorProductos.proveedor',
            'proveedorProductos.pricios' => function ($q) use ($fechaInicio) {
                $q->where('created_at', '>=', $fechaInicio)
                    ->orderBy('created_at', 'desc');
            }
        ])->findOrFail($productoId);

        $historial = collect();

        foreach ($producto->proveedorProductos as $proveedorProducto) {
            foreach ($proveedorProducto->pricios as $precio) {
                $historial->push([
                    'fecha' => $precio->created_at->format('Y-m-d'),
                    'proveedor_id' => $proveedorProducto->proveedor_id,
                    'proveedor_nombre' => $proveedorProducto->proveedor->nombre,
                    'precio_actual' => $precio->precio_actual,
                    'precio_anterior' => $precio->precio_anterior,
                    'moneda' => $proveedorProducto->moneda,
                ]);
            }
        }

        return $historial->sortByDesc('fecha')->values();
    }

    /**
     * Obtener lista de proveedores que tienen un producto
     * 
     * @param int $productoId
     * @return Collection
     */
    public function obtenerProveedoresPorProducto(int $productoId): Collection
    {
        $this->realtimeUpdateService->actualizarTodosLosProveedores($productoId);

        return ProveedorProducto::with(['proveedor', 'pricios' => function ($q) {
            $q->latest('ultima_actualizacion')->limit(1);
        }])
            ->where('producto_id', $productoId)
            ->get()
            ->map(function ($proveedorProducto) {
                $precioActual = $proveedorProducto->pricios->first();
                
                return [
                    'proveedor_producto_id' => $proveedorProducto->id,
                    'proveedor' => [
                        'id' => $proveedorProducto->proveedor->id,
                        'nombre' => $proveedorProducto->proveedor->nombre,
                        'codigo' => $proveedorProducto->proveedor->codigo_proveedor,
                        'activo' => $proveedorProducto->proveedor->activo,
                    ],
                    'codigo_proveedor' => $proveedorProducto->codigo_proveedor,
                    'stock' => $proveedorProducto->stock,
                    'stock_cd' => $proveedorProducto->stock_cd,
                    'stock_total' => $proveedorProducto->stock + $proveedorProducto->stock_cd,
                    'tiene_stock' => ($proveedorProducto->stock > 0 || $proveedorProducto->stock_cd > 0),
                    'moneda' => $proveedorProducto->moneda,
                    'garantia' => $proveedorProducto->garantia,
                    'en_oferta' => $proveedorProducto->en_oferta,
                    'precio_actual' => $precioActual ? $precioActual->precio_actual : null,
                    'ultima_actualizacion' => $proveedorProducto->ultima_actualizacion,
                ];
            });
    }
}
