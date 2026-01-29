<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ProductFilterService
{
    /**
     * Aplicar todos los filtros a la query
     * 
     * @param Builder $query
     * @param Request $request
     * @return Builder
     */
    public function aplicarFiltros(Builder $query, Request $request): Builder
    {
        $this->filtrarPorBusquedaGeneral($query, $request);
        $this->filtrarPorCamposDirectos($query, $request);
        $this->filtrarPorRelaciones($query, $request);
        $this->filtrarPorRangoPrecios($query, $request);

        return $query;
    }

    /**
     * Filtrar por búsqueda general
     * 
     * @param Builder $query
     * @param Request $request
     * @return void
     */
    private function filtrarPorBusquedaGeneral(Builder $query, Request $request): void
    {
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'like', "%{$search}%")
                    ->orWhere('descripcion', 'like', "%{$search}%")
                    ->orWhere('codigo_fabricante', 'like', "%{$search}%")
                    ->orWhere('codigo_barras', 'like', "%{$search}%")
                    ->orWhere('upc', 'like', "%{$search}%");
            });
        }
    }


    /**
     * Filtrar por campos directos del producto
     * 
     * @param Builder $query
     * @param Request $request
     * @return void
     */
    private function filtrarPorCamposDirectos(Builder $query, Request $request): void
    {
        // Filtro por nombre
        if ($request->filled('nombre')) {
            $query->where('nombre', 'like', "%{$request->nombre}%");
        }

        // Filtro por código de fabricante
        if ($request->filled('codigo_fabricante')) {
            $query->where('codigo_fabricante', $request->codigo_fabricante);
        }

        // Filtro por código de barras
        if ($request->filled('codigo_barras')) {
            $query->where('codigo_barras', $request->codigo_barras);
        }

        // Filtro por UPC
        if ($request->filled('upc')) {
            $query->where('upc', $request->upc);
        }

        // Filtro por marca
        if ($request->filled('marca_id')) {
            $query->where('marca_id', $request->marca_id);
        }

        // Filtro por categoría
        if ($request->filled('categoria_id')) {
            $query->where('categoria_id', $request->categoria_id);
        }

        // Filtro por sub-categoría
        if ($request->filled('sub_categoria_id')) {
            $query->where('sub_categoria_id', $request->sub_categoria_id);
        }

        // Filtro por familia
        if ($request->filled('familia_id')) {
            $query->where('familia_id', $request->familia_id);
        }

        // Filtro por grupo
        if ($request->filled('grupo_id')) {
            $query->where('grupo_id', $request->grupo_id);
        }
    }

    /**
     * Filtrar por relaciones
     * 
     * @param Builder $query
     * @param Request $request
     * @return void
     */
    private function filtrarPorRelaciones(Builder $query, Request $request): void
    {
        // Filtro por proveedor
        if ($request->filled('proveedor_id')) {
            $query->whereHas('proveedorProductos', function ($q) use ($request) {
                $q->where('proveedor_id', $request->proveedor_id);
            });
        }

        // Filtro por productos en oferta
        if ($request->filled('en_oferta')) {
            $enOferta = filter_var($request->en_oferta, FILTER_VALIDATE_BOOLEAN);
            $query->whereHas('proveedorProductos', function ($q) use ($enOferta) {
                $q->where('en_oferta', $enOferta);
            });
        }

        // Filtro por productos con stock disponible
        // CORREGIDO: Ahora valida stock O stock_cd
        if ($request->filled('con_stock')) {
            $conStock = filter_var($request->con_stock, FILTER_VALIDATE_BOOLEAN);
            if ($conStock) {
                $query->whereHas('proveedorProductos', function ($q) {
                    $q->where(function ($subQ) {
                        $subQ->where('stock', '>', 0)
                            ->orWhere('stock_cd', '>', 0);
                    });
                });
            }
        }

        // Filtro por tipo de stock específico
        if ($request->filled('tipo_stock')) {
            $tipoStock = $request->tipo_stock; // 'stock', 'stock_cd', 'ambos'
            
            $query->whereHas('proveedorProductos', function ($q) use ($tipoStock) {
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

    /**
     * Filtrar por rango de precios
     * 
     * @param Builder $query
     * @param Request $request
     * @return void
     */
    private function filtrarPorRangoPrecios(Builder $query, Request $request): void
    {
        if ($request->filled('precio_min') || $request->filled('precio_max')) {
            $query->whereHas('proveedorProductos.pricios', function ($q) use ($request) {
                if ($request->filled('precio_min')) {
                    $q->where('precio_actual', '>=', $request->precio_min);
                }
                if ($request->filled('precio_max')) {
                    $q->where('precio_actual', '<=', $request->precio_max);
                }
            });
        }
    }
}
