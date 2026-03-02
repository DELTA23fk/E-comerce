<?php

namespace App\Services;

use App\Models\Producto;
use App\Services\Traits\MapsProducto;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class ProductStockService
{
    use MapsProducto;

    protected ProductRelationService $relationService;

    public function __construct(ProductRelationService $relationService)
    {
        $this->relationService = $relationService;
    }

    public function obtenerDisponibles(Request $request): LengthAwarePaginator
    {
        $builder = Producto::whereHas('proveedorProductos', function ($q) {
            $q->where('stock_total', '>', 0);
        });

        $this->aplicarFiltrosAdicionales($builder, $request);

        $relations = $this->relationService->buildRelations($request);
        if (!empty($relations)) {
            $builder->with($relations);
        }

        // Siempre incluir proveedorProductos con sus almacenes para detalle de stock
        $builder->with(['proveedorProductos' => function ($q) {
            $q->where('stock_total', '>', 0)
              ->with(['almacenes.almacen']);
        }]);

        $perPage = min(max((int)$request->get('per_page', 15), 1), 100);
        return $this->mapPaginator($builder->paginate($perPage));
    }

    public function obtenerAgotados(Request $request): LengthAwarePaginator
    {
        $builder = Producto::whereDoesntHave('proveedorProductos', function ($q) {
            $q->where('stock_total', '>', 0);
        });

        $relations = $this->relationService->buildRelations($request);
        if (!empty($relations)) {
            $builder->with($relations);
        }

        $perPage = min(max((int)$request->get('per_page', 15), 1), 100);
        return $this->mapPaginator($builder->paginate($perPage));
    }

    public function obtenerStockBajo(int $umbral, Request $request): LengthAwarePaginator
    {
        $builder = Producto::whereHas('proveedorProductos', function ($q) use ($umbral) {
            $q->where('stock_total', '>', 0)
              ->where('stock_total', '<=', $umbral);
        });

        $relations = $this->relationService->buildRelations($request);
        if (!empty($relations)) {
            $builder->with($relations);
        }

        $builder->with(['proveedorProductos' => function ($q) use ($umbral) {
            $q->where('stock_total', '>', 0)
              ->where('stock_total', '<=', $umbral)
              ->with(['almacenes.almacen']);
        }]);

        $perPage = min(max((int)$request->get('per_page', 15), 1), 100);
        return $this->mapPaginator($builder->paginate($perPage));
    }

    /**
     * Detalle de stock por producto: desglose por proveedor y por almacén.
     */
    public function obtenerStockPorProducto(int $productoId): array
    {
        $producto = Producto::with([
            'proveedorProductos.proveedor',
            'proveedorProductos.almacenes.almacen',
        ])->findOrFail($productoId);

        $proveedores = $producto->proveedorProductos->map(function ($pp) {
            // Desglose por almacén
            $almacenes = $pp->almacenes->map(fn($a) => [
                'almacen_nombre'       => $a->almacen->nombre ?? null,
                'es_principal'         => $a->almacen->es_principal ?? null,
                'es_cd'                => $a->almacen->es_cd ?? null,
                'cantidad'             => $a->cantidad,
                'backorder'            => $a->backorder,
                'eta_backorder'        => $a->eta_backorder?->format('Y-m-d'),
                'ultima_actualizacion' => $a->ultima_actualizacion,
            ])->values();

            return [
                'proveedor_id'         => $pp->proveedor_id,
                'proveedor_nombre'     => $pp->proveedor->nombre,
                'stock_total'          => $pp->stock_total,
                'tiene_stock'          => $pp->stock_total > 0,
                'moneda'               => $pp->moneda,
                'ultima_actualizacion' => $pp->ultima_actualizacion,
                'almacenes'            => $almacenes,
            ];
        });

        return [
            'producto_id'     => $producto->id,
            'producto_nombre' => $producto->nombre,
            'stock_total'     => $producto->proveedorProductos->sum('stock_total'),
            'tiene_stock'     => $producto->proveedorProductos->sum('stock_total') > 0,
            'proveedores'     => $proveedores->values()->all(),
        ];
    }

    /**
     * Verificar disponibilidad de múltiples productos.
     */
    public function verificarDisponibilidad(array $productosIds): Collection
    {
        return Producto::with(['proveedorProductos'])
            ->whereIn('id', $productosIds)
            ->get()
            ->map(function ($producto) {
                $stockTotal = $producto->proveedorProductos->sum('stock_total');

                return [
                    'producto_id'          => $producto->id,
                    'producto_nombre'      => $producto->nombre,
                    'stock_total'          => $stockTotal,
                    'tiene_stock'          => $stockTotal > 0,
                    'proveedores_con_stock' => $producto->proveedorProductos
                        ->filter(fn($pp) => $pp->stock_total > 0)
                        ->count(),
                ];
            });
    }

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
                  ->where('stock_total', '>', 0);
            });
        }

        // Filtro por tipo de almacén: 'principal', 'cd'
        if ($request->filled('tipo_almacen')) {
            $tipo = $request->tipo_almacen;
            $builder->whereHas('proveedorProductos.almacenes.almacen', function ($q) use ($tipo) {
                if ($tipo === 'principal') {
                    $q->where('es_principal', true);
                } elseif ($tipo === 'cd') {
                    $q->where('es_cd', true);
                }
            });
        }
    }
}