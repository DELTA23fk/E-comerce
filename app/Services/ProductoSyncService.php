<?php

namespace App\Services;

use App\Factories\ProductoFactory;
use App\Models\Categoria;
use App\Models\Familia;
use App\Models\Grupo;
use App\Models\Marca;
use App\Models\Producto;
use App\Models\ProveedorProducto;
use App\Models\SubCategoria;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ProductoSyncService
{
    protected const BATCH_SIZE = 500; //tamano de lote
    protected const CACHE_TTL = 3600; // 1 hora

    protected array $lookupCache = [
        'categories' => [],
        'subcategories' => [],
        'families' => [],
        'groups' => [],
        'brands' => [],
    ];

    /**
     * Obtiene el token y lo cachea por 11 horas
     */
    protected function getCvaToken(): string
    {
        return Cache::remember('cva_bearer_token', now()->addHours(11), function () {
            $config = config('services.cva');
            $response = Http::post(rtrim($config['api_url'], '/') . '/user/login', [
                'user' => $config['client_id'],
                'password' => $config['client_secret'],
            ])->throw();

            return $response->json('token') ?? $response->json('access_token');
        });
    }

    /**
     * Sincroniza productos de CVA con soporte para filtros y paginación usando yield.
     */
    public function syncCVA(int $providerIdDb, array $filters = [], int $page = 1)
    {
        $token = $this->getCvaToken();
        $baseUrl = config('services.cva.api_url');
        
        $queryParams = array_merge($filters, ['page' => $page]);

        $response = Http::withToken($token)
            ->get($baseUrl . 'catalogo_clientes/lista_precios', $queryParams);

        if ($response->unauthorized()) {
            Cache::forget('cva_bearer_token');
            throw new \Exception("Token expirado. Caché limpiado. Intenta de nuevo.");
        }

        if ($response->failed()) {
            throw new \Exception("CVA API Error: " . $response->status());
        }

        // Pre-cargar lookups antes del batch
        $this->preloadLookups();
        $articulos = $response->collect('articulos');
        // Procesar en batches usando yield
        $this->persistBatch(
            $this->transformArticles($articulos, $providerIdDb),
            $providerIdDb
        );

        // Retornar info de paginación
        $paginacion = $response->json('paginacion');
        
        if ($paginacion && $page < $paginacion['total_paginas']) {
            return [
                'current' => $page,
                'total' => $paginacion['total_paginas'],
                'productos_procesados' => $articulos->count()
            ];
        }

        return null;
    }

    /**
     * Transforma artículos usando yield para eficiencia de memoria
     */
    protected function transformArticles(Collection $articles, int $providerIdDb): \Generator
    {
        foreach ($articles as $item) {
            yield ProductoFactory::fromCVA($item);
        }
    }

    /**
     * Pre-carga todas las relaciones maestras en memoria (cache)
     */
    protected function preloadLookups(): void
    {
        $this->lookupCache['categories'] = Categoria::pluck('id', 'nombre')->toArray();
        $this->lookupCache['subcategories'] = SubCategoria::pluck('id', 'nombre')->toArray();
        $this->lookupCache['families'] = Familia::pluck('id', 'nombre')->toArray();
        $this->lookupCache['groups'] = Grupo::pluck('id', 'nombre')->toArray();
        $this->lookupCache['brands'] = Marca::pluck('id', 'nombre')->toArray();
    }

    /**
     * Procesa productos en batches(lotes) para mejor performance
     */
    protected function persistBatch(\Generator $dtos, int $providerIdDb): void
    {
        $batch = [];
        $count = 0;

        foreach ($dtos as $dto) {
            $batch[] = $dto;
            $count++;

            if ($count >= self::BATCH_SIZE) {
                $this->processBatch($batch, $providerIdDb);
                $batch = [];
                $count = 0;
            }
        }

        // Procesar batch restante
        if (!empty($batch)) {
            $this->processBatch($batch, $providerIdDb);
        }
    }

    /**
     * Procesa un batch completo en una sola transacción
     */
    protected function processBatch(array $dtos, int $providerIdDb): void
    {
        DB::transaction(function () use ($dtos, $providerIdDb) {
            // 1. Crear relaciones maestras que no existen
            $this->ensureMasterDataExists($dtos);

            // 2. Preparar datos para upsert
            $productsData = [];
            $providerProductsData = [];
            $pricesData = [];
            $imagesData = [];
            $promotionsData = [];

            foreach ($dtos as $dto) {
                $uniqueKey = $dto->upc ?: ($dto->codigoBarras ?: $dto->codigoFabricante);
                
                // Datos de productos
                $productsData[] = [
                    'upc' => $uniqueKey,
                    'nombre' => $dto->nombre,
                    'descripcion' => $dto->descripcion,
                    'descripcion_tecnica' => $dto->descripcionTecnica,
                    'categoria_id' => $this->lookupCache['categories'][$dto->categoriaNombre] ?? null,
                    'sub_categoria_id' => $this->lookupCache['subcategories'][$dto->subcategoriaNombre ?? 'General'] ?? null,
                    'familia_id' => $this->lookupCache['families'][$dto->familiaNombre ?? 'General'] ?? null,
                    'grupo_id' => $this->lookupCache['groups'][$dto->grupoNombre ?? 'General'] ?? null,
                    'marca_id' => $this->lookupCache['brands'][$dto->marcaNombre ?? 'General'] ?? null,
                    'codigo_fabricante' => $dto->codigoFabricante,
                    'codigo_barras' => $dto->codigoBarras,
                    'updated_at' => now(),
                    'created_at' => now(),
                ];
            }

            // 3. Upsert masivo de productos
            Producto::upsert(
                $productsData,
                ['upc'], // Unique key
                ['nombre', 'descripcion', 'descripcion_tecnica', 'categoria_id', 'sub_categoria_id', 
                 'familia_id', 'grupo_id', 'marca_id', 'codigo_fabricante', 'codigo_barras', 'updated_at']
            );

            // 4. Obtener IDs de productos recién creados/actualizados
            $upcs = array_column($productsData, 'upc');
            $products = Producto::whereIn('upc', $upcs)->get()->keyBy('upc');

            // 5. Preparar provider_products
            foreach ($dtos as $dto) {
                $uniqueKey = $dto->upc ?: ($dto->codigoBarras ?: $dto->codigoFabricante);
                $product = $products[$uniqueKey] ?? null;
                
                if (!$product) continue;

                $providerProductsData[] = [
                    'proveedor_id' => $providerIdDb,
                    'producto_id' => $product->id,
                    'proveedor_producto_id' => $dto->proveedorProductoId,
                    'codigo_proveedor' => $dto->proveedorProductoCodigo,
                    'moneda' => $dto->moneda,
                    'stock' => $dto->stock,
                    'stock_cd' => $dto->stockCD,
                    'garantia' => $dto->garantia,
                    'en_oferta' => $dto->enOferta,
                    'ultima_actualizacion' => now(),
                    'updated_at' => now(),
                    'created_at' => now(),
                ];
            }

            // 6. Upsert provider_products
            if (!empty($providerProductsData)) {
                ProveedorProducto::upsert(
                    $providerProductsData,
                    ['proveedor_id', 'producto_id'],
                    ['proveedor_producto_id', 'codigo_proveedor', 'moneda', 
                     'stock', 'stock_cd', 'garantia', 'en_oferta', 'ultima_actualizacion', 'updated_at']
                );
            }

            // 7. Obtener provider_products para relaciones
            $providerProducts = ProveedorProducto::where('proveedor_id', $providerIdDb)
                ->whereIn('producto_id', $products->pluck('id'))
                ->get()
                ->keyBy(fn($pp) => $pp->proveedor_id . '-' . $pp->producto_id);

            // 8. Preparar precios, imágenes y promociones
            foreach ($dtos as $dto) {
                $uniqueKey = $dto->upc ?: ($dto->codigoBarras ?: $dto->codigoFabricante);
                $product = $products[$uniqueKey] ?? null;
                
                if (!$product) continue;

                $ppKey = $providerIdDb . '-' . $product->id;
                $providerProduct = $providerProducts[$ppKey] ?? null;
                
                if (!$providerProduct) continue;

                // Precios
                $pricesData[] = [
                    'proveedor_producto_id' => $providerProduct->id,
                    'precio_actual' => $dto->precioActual,
                    'precio_anterior' => $dto->precioAnterior,
                    'ultima_actualizacion' => now(),
                    'created_at' => now(),
                ];

                // Imágenes
                foreach ($dto->imagenes as $path) {
                    $imagesData[] = [
                        'url_imagen' => $path,
                        'producto_id' => $product->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                // Promociones
                if ($dto->esOferta || $dto->descuentoTotal !== null) {
                    $promotionsData[] = [
                        'proveedor_producto_id' => $providerProduct->id,
                        'total_descuento' => $dto->descuentoTotal,
                        'moneda_descuento' => $dto->descuentoMoneda,
                        'precio_con_descuento' => $dto->descuentoPrecio,
                        'descuento_precio_moneda' => $dto->descuentoPrecioMoneda,
                        'clave_promocion' => $dto->clavePromocion,
                        'descripcion_promocion' => $dto->promocionDescripcion,
                        'expiracion' => $dto->promocionExpiracion,
                        'disponible_en_promocion' => $dto->disponiblesEnPromocion,
                        'precio_oferta' => $dto->ofertaPrecio,
                        'precio_regular' => $dto->precioRegular,
                        'es_oferta' => $dto->esOferta,
                        'created_at' => now(),
                    ];
                }
            }

            // 9. Inserts masivos
            if (!empty($pricesData)) {
                DB::table('proveedor_producto_precios')->upsert(
                    $pricesData,
                    ['proveedor_producto_id'], // unique key
                    ['precio_actual', 'precio_anterior', 'ultima_actualizacion']
                );
            }

            if (!empty($imagesData)) {
                DB::table('producto_imagenes')->upsert(
                    $imagesData,
                    ['url_imagen','producto_id'],
                    ['updated_at']
                );
            }

            if (!empty($promotionsData)) {
                // Aplicar deduplicación inteligente
                $this->insertPromotionsWithDeduplication($promotionsData);
            }
        });
    }

    /**
     * Asegura que todas las relaciones maestras existan
     */
    protected function ensureMasterDataExists(array $dtos): void
    {
        $categories = [];
        $subcategories = [];
        $families = [];
        $groups = [];
        $brands = [];

        // Recolectar nombres únicos que no están en cache
        foreach ($dtos as $dto) {
            if ($dto->categoriaNombre && !isset($this->lookupCache['categories'][$dto->categoriaNombre])) {
                $categories[$dto->categoriaNombre] = true;
            }
            
            $subCatName = $dto->subcategoriaNombre ?? 'General';
            if (!isset($this->lookupCache['subcategories'][$subCatName])) {
                $subcategories[$subCatName] = true;
            }
            
            $familyName = $dto->familiaNombre ?? 'General';
            if (!isset($this->lookupCache['families'][$familyName])) {
                $families[$familyName] = true;
            }
            
            $groupName = $dto->grupoNombre ?? 'General';
            if (!isset($this->lookupCache['groups'][$groupName])) {
                $groups[$groupName] = true;
            }
            
            $brandName = $dto->marcaNombre ?? 'General';
            if (!isset($this->lookupCache['brands'][$brandName])) {
                $brands[$brandName] = true;
            }
        }

        // Procesar cada tipo de relación usando método helper
        $this->upsertMasterData(Categoria::class, $categories, 'categories');
        $this->upsertMasterData(SubCategoria::class, $subcategories, 'subcategories');
        $this->upsertMasterData(Familia::class, $families, 'families');
        $this->upsertMasterData(Grupo::class, $groups, 'groups');
        $this->upsertMasterData(Marca::class, $brands, 'brands');
    }

    /**
     * Método helper para upsert masivo y actualización de cache
     * 
     * @param string $modelClass Clase del modelo (ej: Categoria::class)
     * @param array $names Array con nombres como keys
     * @param string $cacheKey Key del lookupCache (ej: 'categories')
     */
    protected function upsertMasterData(string $modelClass, array $names, string $cacheKey): void
    {
        if (empty($names)) {
            return;
        }

        // Preparar datos para upsert
        $data = array_map(fn($name) => [
            'nombre' => $name,
            'created_at' => now(),
            'updated_at' => now()
        ], array_keys($names));

        // Upsert masivo
        $modelClass::upsert(
            $data,
            ['nombre'],      // Unique constraint
            ['updated_at']   // Actualizar timestamp si ya existe
        );

        // Recargar cache completo de esta entidad
        $this->lookupCache[$cacheKey] = $modelClass::pluck('id', 'nombre')->toArray();
    }

    /**
     * Inserta promociones evitando duplicados
     */
    protected function insertPromotionsWithDeduplication(array $promotionsData): void
    {
        // Agrupar por provider_product_id
        $grouped = collect($promotionsData)->groupBy('proveedor_producto_id');

        foreach ($grouped as $ppId => $promos) {
            // Obtener última promoción
            $lastPromo = DB::table('proveedor_producto_promociones')
                ->where('proveedor_producto_id', $ppId)
                ->latest('created_at')
                ->first();

            $newPromo = $promos->first();

            // Solo insertar si cambió
            if (!$lastPromo || $this->hasPromotionChangedArray($lastPromo, $newPromo)) {
                DB::table('proveedor_producto_promociones')->insert($newPromo);
            }
        }
    }

    /**
     * Compara promociones usando arrays
     */
    protected function hasPromotionChangedArray($lastPromo, array $newPromo): bool
    {
        return $lastPromo->total_descuento != $newPromo['total_descuento']
            || $lastPromo->precio_con_descuento != $newPromo['precio_con_descuento']
            || $lastPromo->clave_promocion != $newPromo['clave_promocion']
            || $lastPromo->precio_oferta != $newPromo['precio_oferta']
            || $lastPromo->expiracion != $newPromo['expiracion'];
    }
}
