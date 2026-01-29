<?php

namespace App\Services;

use App\Models\Producto;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class ProductStockService
{
    protected ProductRelationService $relationService;

    public function __construct(ProductRelationService $relationService)
    {
        $this->relationService = $relationService;
    }

    /**
     * Obtener productos con stock disponible
     * 
     * @param Request $request
     * @return LengthAwarePaginator
     */
    public function obtenerDisponibles(Request $request): LengthAwarePaginator
    {
        $builder = Producto::whereHas('proveedorProductos', function ($q) {
            $q->where(function ($subQ) {
                $subQ->where('stock', '>', 0)
                    ->orWhere('stock_cd', '>', 0);
            });
        });

        // Aplicar filtros adicionales
        $this->aplicarFiltrosAdicionales($builder, $request);

        // Aplicar relaciones
        $relations = $this->relationService->buildRelations($request);
        if (!empty($relations)) {
            $builder->with($relations);
        }

        // Siempre incluir stock
        $builder->with(['proveedorProductos' => function ($q) {
            $q->where(function ($subQ) {
                $subQ->where('stock', '>', 0)
                    ->orWhere('stock_cd', '>', 0);
            });
        }]);

        $perPage = min(max((int)$request->get('per_page', 15), 1), 100);
        
        return $builder->paginate($perPage);
    }

    /**
     * Obtener productos agotados
     * 
     * @param Request $request
     * @return LengthAwarePaginator
     */
    public function obtenerAgotados(Request $request): LengthAwarePaginator
    {
        $builder = Producto::whereDoesntHave('proveedorProductos', function ($q) {
            $q->where('stock', '>', 0)
                ->orWhere('stock_cd', '>', 0);
        });

        // Aplicar relaciones
        $relations = $this->relationService->buildRelations($request);
        if (!empty($relations)) {
            $builder->with($relations);
        }

        $perPage = min(max((int)$request->get('per_page', 15), 1), 100);
        
        return $builder->paginate($perPage);
    }

    /**
     * Obtener stock de un producto específico
     * 
     * @param int $productoId
     * @return array
     */
    public function obtenerStockPorProducto(int $productoId): array
    {
        $producto = Producto::with(['proveedorProductos.proveedor'])->findOrFail($productoId);

        $stockTotal = 0;
        $stockCdTotal = 0;
        $proveedores = [];

        foreach ($producto->proveedorProductos as $proveedorProducto) {
            $stockTotal += $proveedorProducto->stock;
            $stockCdTotal += $proveedorProducto->stock_cd;
            
            $proveedores[] = [
                'proveedor_id' => $proveedorProducto->proveedor_id,
                'proveedor_nombre' => $proveedorProducto->proveedor->nombre,
                'stock' => $proveedorProducto->stock,
                'stock_cd' => $proveedorProducto->stock_cd,
                'stock_total_proveedor' => $proveedorProducto->stock + $proveedorProducto->stock_cd,
                'ultima_actualizacion' => $proveedorProducto->ultima_actualizacion,
            ];
        }

        return [
            'producto_id' => $producto->id,
            'producto_nombre' => $producto->nombre,
            'stock' => $stockTotal,
            'stock_cd' => $stockCdTotal,
            'stock_total' => $stockTotal + $stockCdTotal,
            'tiene_stock' => ($stockTotal > 0 || $stockCdTotal > 0),
            'proveedores' => $proveedores,
        ];
    }

    /**
     * Obtener productos con stock bajo
     * 
     * @param int $umbral
     * @param Request $request
     * @return LengthAwarePaginator
     */
    public function obtenerStockBajo(int $umbral, Request $request): LengthAwarePaginator
    {
        $builder = Producto::whereHas('proveedorProductos', function ($q) use ($umbral) {
            $q->whereRaw('(stock + stock_cd) > 0')
                ->whereRaw('(stock + stock_cd) <= ?', [$umbral]);
        });

        // Aplicar relaciones
        $relations = $this->relationService->buildRelations($request);
        if (!empty($relations)) {
            $builder->with($relations);
        }

        // Siempre incluir stock
        $builder->with(['proveedorProductos' => function ($q) use ($umbral) {
            $q->whereRaw('(stock + stock_cd) > 0')
                ->whereRaw('(stock + stock_cd) <= ?', [$umbral]);
        }]);

        $perPage = min(max((int)$request->get('per_page', 15), 1), 100);
        
        return $builder->paginate($perPage);
    }

    /**
     * Verificar disponibilidad de múltiples productos
     * 
     * @param array $productosIds
     * @return Collection
     */
    public function verificarDisponibilidad(array $productosIds): Collection
    {
        return Producto::with(['proveedorProductos'])
            ->whereIn('id', $productosIds)
            ->get()
            ->map(function ($producto) {
                $stockTotal = 0;
                $stockCdTotal = 0;

                foreach ($producto->proveedorProductos as $pp) {
                    $stockTotal += $pp->stock;
                    $stockCdTotal += $pp->stock_cd;
                }
                
                return [
                    'producto_id' => $producto->id,
                    'producto_nombre' => $producto->nombre,
                    'stock' => $stockTotal,
                    'stock_cd' => $stockCdTotal,
                    'stock_total' => $stockTotal + $stockCdTotal,
                    'disponible' => ($stockTotal > 0 || $stockCdTotal > 0),
                    'proveedores_con_stock' => $producto->proveedorProductos
                        ->filter(function ($pp) {
                            return $pp->stock > 0 || $pp->stock_cd > 0;
                        })
                        ->count(),
                ];
            });
    }

    /**
     * Aplicar filtros adicionales
     * 
     * @param \Illuminate\Database\Eloquent\Builder $builder
     * @param Request $request
     * @return void
     */
    private function aplicarFiltrosAdicionales($builder, Request $request): void
    {
        if ($request->filled('categoria_id')) {
            $builder->where('categoria_id', $request->categoria_id);
        }

        if ($request->filled('marca_id')) {
            $builder->where('marca_id', $request->marca_id);
        }

        if ($request->filled('proveedor_id')) {
            $builder->whereHas('proveedorProductos', function ($q) use ($request) {
                $q->where('proveedor_id', $request->proveedor_id)
                    ->where(function ($subQ) {
                        $subQ->where('stock', '>', 0)
                            ->orWhere('stock_cd', '>', 0);
                    });
            });
        }

        // Filtro por tipo de stock específico
        if ($request->filled('tipo_stock')) {
            $tipoStock = $request->tipo_stock; // 'stock', 'stock_cd', 'ambos'
            
            $builder->whereHas('proveedorProductos', function ($q) use ($tipoStock) {
                if ($tipoStock === 'stock') {
                    $q->where('stock', '>', 0);
                } elseif ($tipoStock === 'stock_cd') {
                    $q->where('stock_cd', '>', 0);
                } elseif ($tipoStock === 'ambos') {
                    $q->where('stock', '>', 0)
                        ->where('stock_cd', '>', 0);
                }
            });
        }
    }
}
