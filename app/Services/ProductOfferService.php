<?php

namespace App\Services;

use App\Models\Producto;
use App\Services\Traits\MapsProducto;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class ProductOfferService
{
    use MapsProducto;

    protected ProductRelationService $relationService;

    public function __construct(ProductRelationService $relationService)
    {
        $this->relationService = $relationService;
    }

    public function obtenerProductosEnOferta(Request $request): LengthAwarePaginator
    {
        $builder = Producto::whereHas('proveedorProductos', function ($q) {
            $q->where('en_oferta', true)
              ->whereHas('proveedor', fn($q) => $q->where('activo', true));
        });

        $relations = $this->relationService->buildRelations($request);
        if (!empty($relations)) {
            $builder->with($relations);
        }

        $builder->with([
            'proveedorProductos' => function ($q) {
                $q->whereHas('proveedor', fn($q) => $q->where('activo', true))
                  ->where('en_oferta', true)
                  ->with(['proveedor', 'precio', 'promociones']);
            }
        ]);

        if ($request->get('sort_by') === 'descuento') {
            $builder->with(['proveedorProductos.promociones' => function ($q) {
                $q->orderBy('total_descuento', 'desc');
            }]);
        }

        $perPage = min(max((int)$request->get('per_page', 15), 1), 100);
                $paginator = $builder->paginate($perPage);
        return $this->mapPaginator($paginator);
    }

    public function obtenerOfertasPorCategoria(int $categoriaId, Request $request): LengthAwarePaginator
    {
        $builder = Producto::where('categoria_id', $categoriaId)
            ->whereHas('proveedorProductos', function ($q) {
                $q->where('en_oferta', true)
                  ->whereHas('proveedor', fn($q) => $q->where('activo', true));
            });

        $relations = $this->relationService->buildRelations($request);
        if (!empty($relations)) {
            $builder->with($relations);
        }

        $builder->with([
            'proveedorProductos' => function ($q) {
                $q->whereHas('proveedor', fn($q) => $q->where('activo', true))
                  ->where('en_oferta', true)
                  ->with(['proveedor', 'precios', 'promociones']);
            }
        ]);

        $perPage = min(max((int)$request->get('per_page', 15), 1), 100);
                $paginator = $builder->paginate($perPage);
        return $this->mapPaginator($paginator);
    }

    public function obtenerPromocionesActivas(Request $request): LengthAwarePaginator
    {
        $builder = Producto::whereHas('proveedorProductos', function ($q) {
            $q->whereHas('proveedor', fn($q) => $q->where('activo', true))
              ->whereHas('promociones', fn($q) => $q->where('es_oferta', true));
        });

        $relations = $this->relationService->buildRelations($request);
        if (!empty($relations)) {
            $builder->with($relations);
        }

        $builder->with([
            'proveedorProductos' => function ($q) {
                $q->whereHas('proveedor', fn($q) => $q->where('activo', true))
                  ->with([
                      'proveedor',
                      'promociones' => fn($q) => $q->where('es_oferta', true),
                  ]);
            }
        ]);

        $perPage = min(max((int)$request->get('per_page', 15), 1), 100);
                $paginator = $builder->paginate($perPage);
        return $this->mapPaginator($paginator);
    }

    public function obtenerDescuentosMayoresA(int $porcentaje, Request $request): LengthAwarePaginator
    {
        $builder = Producto::whereHas('proveedorProductos', function ($q) use ($porcentaje) {
            $q->whereHas('proveedor', fn($q) => $q->where('activo', true))
              ->whereHas('promociones', function ($q) use ($porcentaje) {
                  $q->where('es_oferta', true)
                    ->whereRaw('CAST(total_descuento AS DECIMAL) >= ?', [$porcentaje]);
              });
        });

        $relations = $this->relationService->buildRelations($request);
        if (!empty($relations)) {
            $builder->with($relations);
        }

        $builder->with([
            'proveedorProductos' => function ($q) use ($porcentaje) {
                $q->whereHas('proveedor', fn($q) => $q->where('activo', true))
                  ->with([
                      'proveedor',
                      'precios',
                      'promociones' => function ($q) use ($porcentaje) {
                          $q->where('es_oferta', true)
                            ->whereRaw('CAST(total_descuento AS DECIMAL) >= ?', [$porcentaje])
                            ->orderBy('total_descuento', 'desc');
                      },
                  ]);
            }
        ]);

        $perPage = min(max((int)$request->get('per_page', 15), 1), 100);
                $paginator = $builder->paginate($perPage);
        return $this->mapPaginator($paginator);
    }

    public function obtenerOfertasDelDia(Request $request): LengthAwarePaginator
    {
        $hoy = Carbon::today();

        $builder = Producto::whereHas('proveedorProductos', function ($q) use ($hoy) {
            $q->whereHas('proveedor', fn($q) => $q->where('activo', true))
              ->where('en_oferta', true)
              ->whereDate('ultima_actualizacion', '=', $hoy);
        });

        $relations = $this->relationService->buildRelations($request);
        if (!empty($relations)) {
            $builder->with($relations);
        }

        $builder->with([
            'proveedorProductos' => function ($q) use ($hoy) {
                $q->whereHas('proveedor', fn($q) => $q->where('activo', true))
                  ->where('en_oferta', true)
                  ->whereDate('ultima_actualizacion', '=', $hoy)
                  ->with(['proveedor', 'precios', 'promociones']);
            }
        ]);

        $perPage = min(max((int)$request->get('per_page', 15), 1), 100);
                $paginator = $builder->paginate($perPage);
        return $this->mapPaginator($paginator);
    }

    public function obtenerOfertasPorVencer(int $dias, Request $request): LengthAwarePaginator
    {
        $hoy        = Carbon::now()->format('Y-m-d');
        $fechaLimite = Carbon::now()->addDays($dias)->format('Y-m-d');

        $builder = Producto::whereHas('proveedorProductos', function ($q) use ($hoy, $fechaLimite) {
            $q->whereHas('proveedor', fn($q) => $q->where('activo', true))
              ->whereHas('promociones', function ($q) use ($hoy, $fechaLimite) {
                  $q->where('es_oferta', true)
                    ->where('expracion', '>=', $hoy)
                    ->where('expracion', '<=', $fechaLimite);
              });
        });

        $relations = $this->relationService->buildRelations($request);
        if (!empty($relations)) {
            $builder->with($relations);
        }

        $builder->with([
            'proveedorProductos' => function ($q) use ($hoy, $fechaLimite) {
                $q->whereHas('proveedor', fn($q) => $q->where('activo', true))
                  ->with([
                      'proveedor',
                      'precios',
                      'promociones' => function ($q) use ($hoy, $fechaLimite) {
                          $q->where('es_oferta', true)
                            ->where('expracion', '>=', $hoy)
                            ->where('expracion', '<=', $fechaLimite)
                            ->orderBy('expracion', 'asc');
                      },
                  ]);
            }
        ]);

        $perPage = min(max((int)$request->get('per_page', 15), 1), 100);
                $paginator = $builder->paginate($perPage);
        return $this->mapPaginator($paginator);
    }
}