<?php

namespace App\Services;

use App\Models\Producto;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class ProductOfferService
{
    protected ProductRelationService $relationService;

    public function __construct(ProductRelationService $relationService)
    {
        $this->relationService = $relationService;
    }

    /**
     * Obtener todos los productos en oferta
     * 
     * @param Request $request
     * @return LengthAwarePaginator
     */
    public function obtenerProductosEnOferta(Request $request): LengthAwarePaginator
    {
        $builder = Producto::whereHas('proveedorProductos', function ($q) {
            $q->where('en_oferta', true);
        });

        // Aplicar relaciones
        $relations = $this->relationService->buildRelations($request);
        if (!empty($relations)) {
            $builder->with($relations);
        }

        // Siempre incluir ofertas
        $builder->with([
            'proveedorProductos' => function ($q) {
                $q->where('en_oferta', true)
                    ->with(['proveedor', 'pricios', 'promociones']);
            }
        ]);

        // Ordenar por descuento (si aplica)
        if ($request->get('sort_by') === 'descuento') {
            $builder->with(['proveedorProductos.promociones' => function ($q) {
                $q->orderBy('total_descuento', 'desc');
            }]);
        }

        $perPage = min(max((int)$request->get('per_page', 15), 1), 100);
        
        return $builder->paginate($perPage);
    }

    /**
     * Obtener ofertas por categoría
     * 
     * @param int $categoriaId
     * @param Request $request
     * @return LengthAwarePaginator
     */
    public function obtenerOfertasPorCategoria(int $categoriaId, Request $request): LengthAwarePaginator
    {
        $builder = Producto::where('categoria_id', $categoriaId)
            ->whereHas('proveedorProductos', function ($q) {
                $q->where('en_oferta', true);
            });

        // Aplicar relaciones
        $relations = $this->relationService->buildRelations($request);
        if (!empty($relations)) {
            $builder->with($relations);
        }

        // Siempre incluir ofertas
        $builder->with([
            'proveedorProductos' => function ($q) {
                $q->where('en_oferta', true)
                    ->with(['proveedor', 'pricios', 'promociones']);
            }
        ]);

        $perPage = min(max((int)$request->get('per_page', 15), 1), 100);
        
        return $builder->paginate($perPage);
    }

    /**
     * Obtener promociones activas
     * 
     * @param Request $request
     * @return LengthAwarePaginator
     */
    public function obtenerPromocionesActivas(Request $request): LengthAwarePaginator
    {
        $builder = Producto::whereHas('proveedorProductos.promociones', function ($q) {
            $q->where('es_oferta', true);
        });

        // Aplicar relaciones
        $relations = $this->relationService->buildRelations($request);
        if (!empty($relations)) {
            $builder->with($relations);
        }

        // Siempre incluir promociones
        $builder->with([
            'proveedorProductos.promociones' => function ($q) {
                $q->where('es_oferta', true);
            },
            'proveedorProductos.proveedor'
        ]);

        $perPage = min(max((int)$request->get('per_page', 15), 1), 100);
        
        return $builder->paginate($perPage);
    }

    /**
     * Obtener descuentos mayores a un porcentaje
     * 
     * @param int $porcentaje
     * @param Request $request
     * @return LengthAwarePaginator
     */
    public function obtenerDescuentosMayoresA(int $porcentaje, Request $request): LengthAwarePaginator
    {
        $builder = Producto::whereHas('proveedorProductos.promociones', function ($q) use ($porcentaje) {
            $q->where('es_oferta', true)
                ->whereRaw('CAST(total_descuento AS DECIMAL) >= ?', [$porcentaje]);
        });

        // Aplicar relaciones
        $relations = $this->relationService->buildRelations($request);
        if (!empty($relations)) {
            $builder->with($relations);
        }

        // Siempre incluir promociones con el filtro
        $builder->with([
            'proveedorProductos.promociones' => function ($q) use ($porcentaje) {
                $q->where('es_oferta', true)
                    ->whereRaw('CAST(total_descuento AS DECIMAL) >= ?', [$porcentaje])
                    ->orderBy('total_descuento', 'desc');
            },
            'proveedorProductos.proveedor',
            'proveedorProductos.pricios'
        ]);

        $perPage = min(max((int)$request->get('per_page', 15), 1), 100);
        
        return $builder->paginate($perPage);
    }

    /**
     * Obtener ofertas del día
     * 
     * @param Request $request
     * @return LengthAwarePaginator
     */
    public function obtenerOfertasDelDia(Request $request): LengthAwarePaginator
    {
        $hoy = Carbon::today();

        $builder = Producto::whereHas('proveedorProductos', function ($q) use ($hoy) {
            $q->where('en_oferta', true)
                ->whereDate('ultima_actualizacion', '=', $hoy);
        });

        // Aplicar relaciones
        $relations = $this->relationService->buildRelations($request);
        if (!empty($relations)) {
            $builder->with($relations);
        }

        // Siempre incluir ofertas del día
        $builder->with([
            'proveedorProductos' => function ($q) use ($hoy) {
                $q->where('en_oferta', true)
                    ->whereDate('ultima_actualizacion', '=', $hoy)
                    ->with(['proveedor', 'pricios', 'promociones']);
            }
        ]);

        $perPage = min(max((int)$request->get('per_page', 15), 1), 100);
        
        return $builder->paginate($perPage);
    }

    /**
     * Obtener ofertas próximas a vencer
     * 
     * @param int $dias
     * @param Request $request
     * @return LengthAwarePaginator
     */
    public function obtenerOfertasPorVencer(int $dias, Request $request): LengthAwarePaginator
    {
        $fechaLimite = Carbon::now()->addDays($dias);

        $builder = Producto::whereHas('proveedorProductos.promociones', function ($q) use ($fechaLimite) {
            $q->where('es_oferta', true)
                ->where('expracion', '<=', $fechaLimite->format('Y-m-d'))
                ->where('expracion', '>=', Carbon::now()->format('Y-m-d'));
        });

        // Aplicar relaciones
        $relations = $this->relationService->buildRelations($request);
        if (!empty($relations)) {
            $builder->with($relations);
        }

        // Siempre incluir promociones por vencer
        $builder->with([
            'proveedorProductos.promociones' => function ($q) use ($fechaLimite) {
                $q->where('es_oferta', true)
                    ->where('expracion', '<=', $fechaLimite->format('Y-m-d'))
                    ->where('expracion', '>=', Carbon::now()->format('Y-m-d'))
                    ->orderBy('expracion', 'asc');
            },
            'proveedorProductos.proveedor',
            'proveedorProductos.pricios'
        ]);

        $perPage = min(max((int)$request->get('per_page', 15), 1), 100);
        
        return $builder->paginate($perPage);
    }
}
