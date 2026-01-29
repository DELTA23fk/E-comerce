<?php

namespace App\Services;

use App\Models\Producto;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class ProductSearchService
{
    protected ProductRelationService $relationService;

    public function __construct(ProductRelationService $relationService)
    {
        $this->relationService = $relationService;
    }

    /**
     * Búsqueda general por query
     * 
     * @param string|null $query
     * @param Request $request
     * @return LengthAwarePaginator
     */
    public function busquedaGeneral(?string $query, Request $request): LengthAwarePaginator
    {
        $builder = Producto::query();

        if ($query) {
            $builder->where(function ($q) use ($query) {
                $q->where('nombre', 'like', "%{$query}%")
                    ->orWhere('descripcion', 'like', "%{$query}%")
                    ->orWhere('descripcion_tecnica', 'like', "%{$query}%")
                    ->orWhere('codigo_fabricante', 'like', "%{$query}%")
                    ->orWhere('codigo_barras', 'like', "%{$query}%")
                    ->orWhere('upc', 'like', "%{$query}%");
            });
        }

        // Aplicar relaciones
        $relations = $this->relationService->buildRelations($request);
        if (!empty($relations)) {
            $builder->with($relations);
        }

        // Ordenar por relevancia (productos que coincidan en nombre primero)
        if ($query) {
            $builder->orderByRaw("CASE 
                WHEN nombre LIKE ? THEN 1 
                WHEN descripcion LIKE ? THEN 2 
                ELSE 3 
            END", ["%{$query}%", "%{$query}%"]);
        }

        $perPage = min(max((int)$request->get('per_page', 15), 1), 100);
        
        return $builder->paginate($perPage);
    }

    /**
     * Búsqueda por texto específico
     * 
     * @param string $texto
     * @param Request $request
     * @return LengthAwarePaginator
     */
    public function buscarPorTexto(string $texto, Request $request): LengthAwarePaginator
    {
        $builder = Producto::query();

        $campos = $request->input('campos', ['nombre', 'descripcion']);
        
        $builder->where(function ($q) use ($texto, $campos) {
            foreach ($campos as $campo) {
                if (in_array($campo, ['nombre', 'descripcion', 'descripcion_tecnica', 'codigo_fabricante', 'codigo_barras', 'upc'])) {
                    $q->orWhere($campo, 'like', "%{$texto}%");
                }
            }
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
     * Búsqueda avanzada con múltiples criterios
     * 
     * @param array $criterios
     * @return LengthAwarePaginator
     */
    public function busquedaAvanzada(array $criterios): LengthAwarePaginator
    {
        $builder = Producto::query();

        // Nombre
        if (!empty($criterios['nombre'])) {
            $builder->where('nombre', 'like', "%{$criterios['nombre']}%");
        }

        // Descripción
        if (!empty($criterios['descripcion'])) {
            $builder->where('descripcion', 'like', "%{$criterios['descripcion']}%");
        }

        // Marca
        if (!empty($criterios['marca_id'])) {
            $builder->where('marca_id', $criterios['marca_id']);
        }

        // Categoría
        if (!empty($criterios['categoria_id'])) {
            $builder->where('categoria_id', $criterios['categoria_id']);
        }

        // Sub-categoría
        if (!empty($criterios['sub_categoria_id'])) {
            $builder->where('sub_categoria_id', $criterios['sub_categoria_id']);
        }

        // Familia
        if (!empty($criterios['familia_id'])) {
            $builder->where('familia_id', $criterios['familia_id']);
        }

        // Grupo
        if (!empty($criterios['grupo_id'])) {
            $builder->where('grupo_id', $criterios['grupo_id']);
        }

        // Rango de precios
        if (!empty($criterios['precio_min']) || !empty($criterios['precio_max'])) {
            $builder->whereHas('proveedorProductos.pricios', function ($q) use ($criterios) {
                if (!empty($criterios['precio_min'])) {
                    $q->where('precio_actual', '>=', $criterios['precio_min']);
                }
                if (!empty($criterios['precio_max'])) {
                    $q->where('precio_actual', '<=', $criterios['precio_max']);
                }
            });
        }

        // Stock disponible
        if (!empty($criterios['con_stock'])) {
            $builder->whereHas('proveedorProductos', function ($q) {
                $q->where('stock', '>', 0)->orWhere('stock_cd','>',0);
            });
        }

        // En oferta
        if (!empty($criterios['en_oferta'])) {
            $builder->whereHas('proveedorProductos', function ($q) {
                $q->where('en_oferta', true);
            });
        }

        // Aplicar relaciones si vienen en los criterios
        if (!empty($criterios['include_relations'])) {
            $relations = $criterios['include_relations'];
            if (is_string($relations)) {
                $relations = explode(',', $relations);
            }
            $builder->with($relations);
        }

        $perPage = min(max((int)($criterios['per_page'] ?? 15), 1), 100);
        
        return $builder->paginate($perPage);
    }

    /**
     * Obtener sugerencias de autocompletado
     * 
     * @param string $termino
     * @param int $limite
     * @return Collection
     */
    public function obtenerSugerencias(string $termino, int $limite = 10): Collection
    {
        return Producto::select('id', 'nombre', 'codigo_fabricante')
            ->where(function ($q) use ($termino) {
                $q->where('nombre', 'like', "%{$termino}%")
                    ->orWhere('codigo_fabricante', 'like', "%{$termino}%");
            })
            ->limit($limite)
            ->get()
            ->map(function ($producto) {
                return [
                    'id' => $producto->id,
                    'nombre' => $producto->nombre,
                    'codigo' => $producto->codigo_fabricante,
                ];
            });
    }
}
