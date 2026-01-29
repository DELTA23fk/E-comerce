<?php

namespace App\Services;

use App\Models\Producto;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class ProductCatalogService
{
   protected ProductRelationService $relationService;

    public function __construct(ProductRelationService $relationService)
    {
        $this->relationService = $relationService;
    }

    /**
     * Obtener productos por categoría
     * 
     * @param int $categoriaId
     * @param Request $request
     * @return LengthAwarePaginator
     */
    public function obtenerPorCategoria(int $categoriaId, Request $request): LengthAwarePaginator
    {
        return $this->obtenerPorCampo('categoria_id', $categoriaId, $request);
    }

    /**
     * Obtener productos por sub-categoría
     * 
     * @param int $subCategoriaId
     * @param Request $request
     * @return LengthAwarePaginator
     */
    public function obtenerPorSubCategoria(int $subCategoriaId, Request $request): LengthAwarePaginator
    {
        return $this->obtenerPorCampo('sub_categoria_id', $subCategoriaId, $request);
    }

    /**
     * Obtener productos por familia
     * 
     * @param int $familiaId
     * @param Request $request
     * @return LengthAwarePaginator
     */
    public function obtenerPorFamilia(int $familiaId, Request $request): LengthAwarePaginator
    {
        return $this->obtenerPorCampo('familia_id', $familiaId, $request);
    }

    /**
     * Obtener productos por grupo
     * 
     * @param int $grupoId
     * @param Request $request
     * @return LengthAwarePaginator
     */
    public function obtenerPorGrupo(int $grupoId, Request $request): LengthAwarePaginator
    {
        return $this->obtenerPorCampo('grupo_id', $grupoId, $request);
    }

    /**
     * Obtener productos por marca
     * 
     * @param int $marcaId
     * @param Request $request
     * @return LengthAwarePaginator
     */
    public function obtenerPorMarca(int $marcaId, Request $request): LengthAwarePaginator
    {
        return $this->obtenerPorCampo('marca_id', $marcaId, $request);
    }

    /**
     * Obtener productos relacionados
     * 
     * @param int $productoId
     * @param int $limite
     * @param Request $request
     * @return Collection
     */
    public function obtenerRelacionados(int $productoId, int $limite, Request $request): Collection
    {
        $producto = Producto::findOrFail($productoId);

        $builder = Producto::where('id', '!=', $productoId);

        // Buscar productos relacionados por categoría, marca o familia
        $builder->where(function ($q) use ($producto) {
            $q->where('categoria_id', $producto->categoria_id)
                ->orWhere('marca_id', $producto->marca_id)
                ->orWhere('familia_id', $producto->familia_id);
        });

        // Aplicar relaciones
        $relations = $this->relationService->buildRelations($request);
        if (!empty($relations)) {
            $builder->with($relations);
        }

        // Ordenar por similitud (primero los de la misma categoría)
        $builder->orderByRaw("
            CASE 
                WHEN categoria_id = ? THEN 1 
                WHEN marca_id = ? THEN 2 
                WHEN familia_id = ? THEN 3 
                ELSE 4 
            END
        ", [$producto->categoria_id, $producto->marca_id, $producto->familia_id]);

        return $builder->limit($limite)->get();
    }

    /**
     * Método auxiliar para obtener productos por un campo específico
     * 
     * @param string $campo
     * @param mixed $valor
     * @param Request $request
     * @return LengthAwarePaginator
     */
    private function obtenerPorCampo(string $campo, $valor, Request $request): LengthAwarePaginator
    {
        $builder = Producto::where($campo, $valor);

        // Aplicar filtros adicionales si vienen en el request
        if ($request->filled('con_stock')) {
            $builder->whereHas('proveedorProductos', function ($q) {
                $q->where(function ($subQ) {
                    $subQ->where('stock', '>', 0)
                        ->orWhere('stock_cd', '>', 0);
                });
            });
        }

        if ($request->filled('en_oferta')) {
            $builder->whereHas('proveedorProductos', function ($q) {
                $q->where('en_oferta', true);
            });
        }

        // Aplicar relaciones
        $relations = $this->relationService->buildRelations($request);
        if (!empty($relations)) {
            $builder->with($relations);
        }

        // Ordenamiento
        $sortBy = $request->get('sort_by', 'nombre');
        $sortOrder = $request->get('sort_order', 'asc');
        
        if (in_array($sortBy, ['id', 'nombre', 'created_at', 'updated_at'])) {
            $builder->orderBy($sortBy, $sortOrder);
        }

        $perPage = min(max((int)$request->get('per_page', 15), 1), 100);
        
        return $builder->paginate($perPage);
    }
}
