<?php

namespace App\Services;

use App\Models\Producto;
use App\Services\Traits\MapsProducto;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class ProductService
{
    use MapsProducto;

    protected ProductFilterService $filterService;
    protected ProductRelationService $relationService;
    protected ProductRealtimeUpdateService $realtimeUpdateService;

    public function __construct(
        ProductFilterService $filterService,
        ProductRelationService $relationService,
        ProductRealtimeUpdateService $realtimeUpdateService

    ) {
        $this->filterService = $filterService;
        $this->relationService = $relationService;
        $this->realtimeUpdateService = $realtimeUpdateService;


    }

    private function productBaseBuilder():Builder
    {
        return Producto::query()->select('id','nombre','codigo_fabricante','codigo_barras','upc','descripcion','descripcion_tecnica','marca_id','categoria_id','sub_categoria_id','familia_id','grupo_id');
    }

    /**
     * Obtener productos con filtros y paginación
     */
    public function getProductosConFiltros(Request $request): LengthAwarePaginator
    {
        $query = $this->productBaseBuilder();

        // Aplicar filtros
        $query = $this->filterService->aplicarFiltros($query, $request);

        // Aplicar relaciones dinámicas
        $relations = $this->relationService->buildRelations($request);
        if (!empty($relations)) {
            $query->with($relations);
        }

        // Aplicar ordenamiento
        $query = $this->aplicarOrdenamiento($query, $request);

        // Paginación
        $perPage = $this->getPerPage($request);
        $paginator = $query->paginate($perPage);
        return $this->mapPaginator($paginator);
    }

    /**
     * Obtener un producto por ID
     */
    public function getProductoPorId(int $id, Request $request): ?Producto
    {
         // Detectar si se están solicitando proveedores
        $incluyeProveedores = $request->boolean('include_proveedores') || 
                             $request->boolean('include_proveedor_detalle') ||
                             $request->boolean('include_precios') ||
                             $request->boolean('include_promociones');

        //ACTUALIZAR AUTOMÁTICAMENTE si se solicitan proveedores
        if ($incluyeProveedores) {
            $logs = $this->realtimeUpdateService->actualizarTodosLosProveedoresConThrottling($id);
            \Log::info("[ProductService] Actualizacion estatus del producto: omitidos: {$logs['omitidos']}");
        }

        $query = $this->productBaseBuilder();

        $relations = $this->relationService->buildRelations($request);
        if (!empty($relations)) {
            $query->with($relations);
        }

        $producto = $query->find($id);
        return $producto ? $this->mapProducto($producto) : null;
    }

    /**
     * Buscar por código de fabricante
     */
    public function buscarPorCodigoFabricante(string $codigo, Request $request): ?Collection
    {
        $incluyeProveedores = $request->boolean('include_proveedores') || 
                                 $request->boolean('include_proveedor_detalle') ||
                                 $request->boolean('include_precios') ||
                                 $request->boolean('include_promociones');

        $query = $this->productBaseBuilder();

        $query->where('codigo_fabricante', $codigo);

        $relations = $this->relationService->buildRelations($request);
        if (!empty($relations)) {
            $query->with($relations);
        }
        $productos = $query->get();
        
        if ($productos->isNotEmpty() && $incluyeProveedores) {


            foreach($productos as $item){
                $this->realtimeUpdateService->actualizarTodosLosProveedoresConThrottling($item->id);
                $item->refresh();
                if (!empty($relations)) {
                    $item->load($relations);
                }
            }
            
        }
        
        return $productos->map(fn($p) => $this->mapProducto($p));

    }

    /**
     * Buscar por código de barras
     */
    public function buscarPorCodigoBarras(string $codigoBarras, Request $request): ?Producto
    {
        $query = $this->productBaseBuilder();

        $query->where('codigo_barras', $codigoBarras);

        $relations = $this->relationService->buildRelations($request);
        if (!empty($relations)) {
            $query->with($relations);
        }

        $producto = $query->first();

        if ($producto) {
            $incluyeProveedores = $request->boolean('include_proveedores') || 
                                 $request->boolean('include_proveedor_detalle') ||
                                 $request->boolean('include_precios') ||
                                 $request->boolean('include_promociones');

            if ($incluyeProveedores) {
            $this->realtimeUpdateService->actualizarTodosLosProveedoresConThrottling($producto->id);
                $producto->refresh();
                if (!empty($relations)) {
                    $producto->load($relations);
                }
            }
        }

        return $producto;
    }

    /**
     * Buscar por UPC
     */
    public function buscarPorUPC(string $upc, Request $request): ?Producto
    {
        $query = $this->productBaseBuilder();

        $query->where('upc', $upc);

        $relations = $this->relationService->buildRelations($request);
        if (!empty($relations)) {
            $query->with($relations);
        }

         $producto = $query->first();

        // ACTUALIZAR AUTOMÁTICAMENTE si el producto existe y se solicitan proveedores
        if ($producto) {
            $incluyeProveedores = $request->boolean('include_proveedores') || 
                                 $request->boolean('include_proveedor_detalle') ||
                                 $request->boolean('include_precios') ||
                                 $request->boolean('include_promociones');

            if ($incluyeProveedores) {
                $this->realtimeUpdateService->actualizarTodosLosProveedoresConThrottling($producto->id);
                $producto->refresh();
                if (!empty($relations)) {
                    $producto->load($relations);
                }
            }
        }

        return $producto;
    }

    /**
     * Buscar por rango de precios
     */
    public function buscarPorRangoPrecio(?float $min, ?float $max, Request $request): LengthAwarePaginator
    {
        $query = $this->productBaseBuilder();


        if ($min !== null || $max !== null) {
            $query->whereHas('proveedorProductos.precio', function ($q) use ($min, $max) {
                if ($min !== null) {
                    $q->where('precio_actual', '>=', $min);
                }
                if ($max !== null) {
                    $q->where('precio_actual', '<=', $max);
                }
            });
        }

        $relations = $this->relationService->buildRelations($request);
        if (!empty($relations)) {
            $query->with($relations);
        }

        $perPage = $this->getPerPage($request);
        $paginator = $query->paginate($perPage);
        return $this->mapPaginator($paginator);
    }

    /**
     * Obtener productos recientes
     */
    public function obtenerRecientes(int $dias, Request $request): LengthAwarePaginator
    {
        $fechaInicio = Carbon::now()->subDays($dias);
        
        $query = $this->productBaseBuilder();

        $query->where('created_at', '>=', $fechaInicio);

        $relations = $this->relationService->buildRelations($request);
        if (!empty($relations)) {
            $query->with($relations);
        }

        $query->orderBy('created_at', 'desc');

        $perPage = $this->getPerPage($request);
        $paginator = $query->paginate($perPage);
        return $this->mapPaginator($paginator);
    }

    /**
     * Obtener productos populares
     */
    public function obtenerPopulares(int $limite, Request $request): Collection
    {
        // Aquí  implementar lógica basada en ventas, visualizaciones, etc.
        // Por ahora, retornamos los más recientes
        $query = $this->productBaseBuilder();


        $relations = $this->relationService->buildRelations($request);
        if (!empty($relations)) {
            $query->with($relations);
        }

        return $query->orderBy('created_at', 'desc')
            ->limit($limite)
            ->get()
            ->map(fn($p) => $this->mapProducto($p));
    }

    /**
     * Obtener productos destacados
     */
    public function obtenerDestacados(Request $request): LengthAwarePaginator
    {
        $query = Producto::whereHas('proveedorProductos', function ($q) {
            $q->where('en_oferta', true)
                ->where(function ($subQ) {
                    $subQ->where('stock', '>', 0)
                        ->orWhere('stock_cd', '>', 0);
                });
        });

        $relations = $this->relationService->buildRelations($request);
        if (!empty($relations)) {
            $query->with($relations);
        }

        $perPage = $this->getPerPage($request);
        $paginator = $query->paginate($perPage);
        return $this->mapPaginator($paginator);
    }

    /**
     * Obtener imágenes de un producto
     */
    public function obtenerImagenes(int $productoId): Collection
    {
        $producto = Producto::with('imagenes')->findOrFail($productoId);
        return $producto->imagenes;
    }

    /**
     * Obtener imagen principal
     */
    public function obtenerImagenPrincipal(int $productoId): ?array
    {
        $producto = Producto::with('imagenes')->findOrFail($productoId);
        $imagenPrincipal = $producto->imagenes->first();
        
        return $imagenPrincipal ? [
            'id' => $imagenPrincipal->id,
            'url_imagen' => $imagenPrincipal->url_imagen,
            'producto_id' => $imagenPrincipal->producto_id,
        ] : null;
    }

    /**
     * Obtener resumen de estadísticas
     */
    public function obtenerResumenEstadisticas(): array
    {
        return [
            'total_productos' => Producto::count(),

            'productos_con_stock' => Producto::whereHas('proveedorProductos', function ($q) {
                $q->where('stock', '>', 0);
            })->count(),

            'productos_con_stock_cd' => Producto::whereHas('proveedorProductos', function ($q) {
                $q->where('stock_cd', '>', 0);
            })->count(),

            'productos_en_oferta' => Producto::whereHas('proveedorProductos', function ($q) {
                $q->where('en_oferta', true);
            })->count(),

            'productos_sin_stock' => Producto::whereDoesntHave('proveedorProductos', function ($q) {
                $q->where('stock', '>', 0);
            })->count(),
            'productos_sin_stock_cd' => Producto::whereDoesntHave('proveedorProductos', function ($q) {
                $q->where('stock_cd', '>', 0);
            })->count(),

        ];
    }

    /**
     * Contar productos por categoría
     */
    public function contarPorCategoria(): Collection
    {
        return Producto::selectRaw('categoria_id, COUNT(*) as total')
            ->with('categoria:id,nombre')
            ->groupBy('categoria_id')
            ->get()
            ->map(function ($item) {
                return [
                    'categoria_id' => $item->categoria_id,
                    'categoria_nombre' => $item->categoria->nombre ?? 'Sin categoría',
                    'total' => $item->total,
                ];
            });
    }

    /**
     * Contar productos por marca
     */
    public function contarPorMarca(): Collection
    {
        return Producto::selectRaw('marca_id, COUNT(*) as total')
            ->with('marca:id,nombre')
            ->groupBy('marca_id')
            ->get()
            ->map(function ($item) {
                return [
                    'marca_id' => $item->marca_id,
                    'marca_nombre' => $item->marca->nombre ?? 'Sin marca',
                    'total' => $item->total,
                ];
            });
    }

    /**
     * Aplicar ordenamiento
     */
    private function aplicarOrdenamiento(Builder $query, Request $request): Builder
    {
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        
        $allowedSortFields = [
            'id', 'nombre', 'codigo_fabricante', 'codigo_barras', 
            'upc', 'created_at', 'updated_at'
        ];
        
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        return $query;
    }

    /**
     * Obtener cantidad por página
     */
    private function getPerPage(Request $request): int
    {
        $perPage = $request->get('per_page', 15);
        return min(max((int)$perPage, 1), 100);
    }
}
