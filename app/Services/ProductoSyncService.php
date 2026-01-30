<?php

namespace App\Services;

use App\Data\Cva\ArticuloData;
use App\Data\Producto\ProductoData;
use App\Factories\ProductoFactory;
use App\Models\Categoria;
use App\Models\Familia;
use App\Models\Grupo;
use App\Models\Marca;
use App\Models\Producto;
use App\Models\ProveedorProducto;
use App\Models\SubCategoria;
use App\Repository\CvaRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProductoSyncService
{
    public function __construct(
        private readonly CvaRepository $apiCva
    ) {}
    protected const BATCH_SIZE = 500;
    protected const CACHE_TTL = 3600;

    protected array $lookupCache = [
        'categories' => [],
        'subcategories' => [],
        'families' => [],
        'groups' => [],
        'brands' => [],
    ];

    // =========================================================================
    // REGISTRO INICIAL DE ARTICULOS CVA EN EL SISTEMA
    // =========================================================================

    /**
     * INSERCIÓN INICIAL - Primera sincronización completa desde API CVA
     * Crea productos, relaciones, precios, stock, promociones e imágenes
     * 
     * @param int $providerIdDb ID del proveedor en BD
     * @param array $filters Filtros para la API (ej: ['marca' => 'DELL'])
     * @param int $page Página actual
     * @return array|null Info de paginación o null si terminó
     */
    public function initialSyncCVA(int $providerIdDb, array $filters = [], int $page = 1): ?array
    {
        $data = $this->apiCva->getProductsGeneral($filters,$page);
        
        if ($data->articulos->count() === 0) {
            return null;
        }

        $this->preloadLookups();
        
        $dtos = collect();
        foreach ($data->articulos as $item) {
            $dtos->push(ProductoFactory::fromCVA($item));
        }

        // Procesar en batches
        $this->persistInitialBatch($dtos->all(), $providerIdDb);

        return $this->buildPaginationResponse($page, $data->articulos->count(),$data->paginacion->totalPaginas);
    }

    public function getOneByClave(array $filters){
        return $this->apiCva->getSingleProductByClave($filters);
        
    }
    public function getProductsGeneral(array $filters = [], int $page = 1)
    {
        return $this->apiCva->getProductsGeneral($filters, $page);
    }



    /**
     * ACTUALIZACIÓN DE PRECIOS - Solo actualiza precios sin tocar stock ni promociones
     * Útil para sincronizaciones rápidas de cambios de precio
     * 
     * @param int $providerIdDb ID del proveedor
     * @param array $filters Filtros opcionales
     * @return array Estadísticas de la actualización
     */
    public function updatePricesCVA(int $providerIdDb, array $filters = []): array
    {
        $data = $this->apiCva->getProductsGeneral($filters,1);
        
        $stats = [
            'total' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'errors' => 0
        ];

        foreach ($data->articulos->chunk(self::BATCH_SIZE) as $chunk) {
            $result = $this->updatePricesBatch($chunk, $providerIdDb);
            $stats['total'] += $result['total'];
            $stats['updated'] += $result['updated'];
            $stats['unchanged'] += $result['unchanged'];
            $stats['errors'] += $result['errors'];
        }

        return $stats;
    }

    /**
     * ACTUALIZACIÓN DE STOCK - Solo actualiza existencias (stock y stock_cd)
     * Ideal para sincronizaciones frecuentes de inventario
     * 
     * @param int $providerIdDb ID del proveedor
     * @param array $filters Filtros opcionales
     * @return array Estadísticas de la actualización
     */
    public function updateStockCVA(int $providerIdDb, array $filters = []): array
    {
        $data = $this->apiCva->getProductsGeneral($filters);
        
        $stats = [
            'total' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'errors' => 0
        ];

        foreach ($data->articulos->chunk(self::BATCH_SIZE) as $chunk) {
            $result = $this->updateStockBatch($chunk, $providerIdDb);
            $stats['total'] += $result['total'];
            $stats['updated'] += $result['updated'];
            $stats['unchanged'] += $result['unchanged'];
            $stats['errors'] += $result['errors'];
        }

        return $stats;
    }

    /**
     * ACTUALIZACIÓN DE PROMOCIONES - Solo actualiza/crea promociones activas
     * Detecta cambios en promociones y mantiene histórico
     * 
     * @param int $providerIdDb ID del proveedor
     * @param array $filters Filtros opcionales
     * @return array Estadísticas de la actualización
     */
    public function updatePromotionsCVA(int $providerIdDb, array $filters = []): array
    {
        $data = $this->apiCva->getProductsGeneral($filters);
        
        $stats = [
            'total' => 0,
            'created' => 0,
            'unchanged' => 0,
            'expired' => 0,
            'errors' => 0
        ];

        foreach ($data->articulos->chunk(self::BATCH_SIZE) as $chunk) {
            $result = $this->updatePromotionsBatch($chunk, $providerIdDb);
            $stats['total'] += $result['total'];
            $stats['created'] += $result['created'];
            $stats['unchanged'] += $result['unchanged'];
            $stats['expired'] += $result['expired'];
            $stats['errors'] += $result['errors'];
        }

        return $stats;
    }

    /**
     * ACTUALIZACIÓN COMPLETA POR ARTÍCULO - Actualiza precio, stock y promociones de un producto específico
     * Útil para sincronizaciones bajo demanda o webhooks
     * 
     * @param int $providerIdDb ID del proveedor
     * @param string $articleId ID del artículo en CVA (clave, SKU, etc)
     * @return array Resultado de la actualización
     */
    public function updateSingleArticleCVA(int $providerIdDb, string $articleId): array
    {
        
        // Buscar el artículo específico en la API
        $filters = ['id' => $articleId]; // o ['clave' => $articleId] según API
        $data = $this->apiCva->getSingleProductByClave($filters);

        if ($data['articulos']->isEmpty()) {
            return [
                'success' => false,
                'error' => "Artículo {$articleId} no encontrado en CVA"
            ];
        }

        $article = $data['articulos']->first();
        $dto = ProductoFactory::fromCVA($article);

        try {
            DB::transaction(function () use ($dto, $providerIdDb) {
                // 1. Buscar/crear producto
                $product = $this->findOrCreateProduct($dto);
                
                // 2. Buscar/crear provider_product
                $providerProduct = $this->findOrCreateProviderProduct($dto, $product->id, $providerIdDb);
                
                // 3. Actualizar precio
                $this->updateSinglePrice($dto, $providerProduct->id);
                
                // 4. Actualizar stock
                $this->updateSingleStock($dto, $providerProduct->id);
                
                // 5. Actualizar promoción
                $this->updateSinglePromotion($dto, $providerProduct->id);
                
                // 6. Actualizar imágenes
                $this->updateProductImages($dto, $product->id);
            });

            return [
                'success' => true,
                'article_id' => $articleId,
                'message' => 'Artículo actualizado correctamente'
            ];

        } catch (\Exception $e) {
            Log::error("Error actualizando artículo {$articleId}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'article_id' => $articleId,
                'error' => $e->getMessage()
            ];
        }
    }

    // =========================================================================
    // MÉTODOS DE INSERCIÓN INICIAL (BATCH COMPLETO)
    // =========================================================================

    /**
     * Procesa batch completo para inserción inicial
     * Crea todo: productos, relaciones, precios, stock, promociones, imágenes
     */
    protected function persistInitialBatch(array $dtos, int $providerIdDb): void
    {
        if (empty($dtos)) {
            return;
        }

        DB::transaction(function () use ($dtos, $providerIdDb) {
            // 1. Crear relaciones maestras
            $this->ensureMasterDataExists($dtos);

            // 2. Validar y filtrar DTOs con unique key
            $validDtos = $this->filterValidDtos($dtos);

            // 3. Upsert productos
            $products = $this->upsertProducts($validDtos);

            // 4. Upsert provider_products
            $providerProducts = $this->upsertProviderProducts($validDtos, $products, $providerIdDb);

            // 5. Insert precios iniciales
            $this->insertInitialPrices($validDtos, $providerProducts);

            // 6. Upsert imágenes
            $this->upsertImages($validDtos, $products);

            // 7. Insert promociones si existen
            $this->insertInitialPromotions($validDtos, $providerProducts);
        });
    }

    // =========================================================================
    // MÉTODOS DE ACTUALIZACIÓN DE PRECIOS
    // =========================================================================

    /**
     * Actualiza precios en batch, solo si cambiaron
     */
    public function updatePricesBatch(array $articles, int $providerIdDb): array
    {
        $stats = ['total' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => 0];

        DB::transaction(function () use ($articles, $providerIdDb, &$stats) {
            foreach ($articles as $article) {
                try {
                    $dto = ProductoFactory::fromCVA($article);
                    $stats['total']++;

                    // Buscar provider_product
                    $providerProduct = $this->findProviderProductByDto($dto, $providerIdDb);
                    
                    if (!$providerProduct) {
                        $stats['errors']++;
                        continue;
                    }

                    // Obtener el registro de precio actual para comparar y luego actualizar
                    $currentPriceRecord = DB::table('proveedor_producto_precios')
                        ->where('proveedor_producto_id', $providerProduct->id)
                        ->first(); // O ->latest('created_at')->first() si hay varios

                    $newPrice = $dto->precioActual;

                    if (!$currentPriceRecord || $currentPriceRecord->precio_actual != $newPrice) {
                        
                        // CAMBIO: De insert a update
                        DB::table('proveedor_producto_precios')
                            ->where('proveedor_producto_id', $providerProduct->id)
                            ->update([
                                'precio_anterior' => $currentPriceRecord->precio_actual ?? null,
                                'precio_actual' => $newPrice,
                                'ultima_actualizacion' => now(),
                                'updated_at' => now(), // Generalmente se usa updated_at en lugar de created_at para updates
                            ]);

                        $stats['updated']++;
                    } else {
                        $stats['unchanged']++;
                    }

                } catch (\Exception $e) {
                    Log::error("Error actualizando precio", [
                        'article' => $article['id'] ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);
                    $stats['errors']++;
                }
            }
        });

        return $stats;
    }

    /**
     * Actualiza precio de un solo producto
     */
    protected function updateSinglePrice($dto, int $providerProductId): void
    {
        $currentPrice = DB::table('proveedor_producto_precios')
            ->where('proveedor_producto_id', $providerProductId)
            ->latest('created_at')
            ->first();

        $newPrice = $dto->precioActual;

        if (!$currentPrice || $currentPrice->precio_actual != $newPrice) {
            DB::table('proveedor_producto_precios')->insert([
                'proveedor_producto_id' => $providerProductId,
                'precio_actual' => $newPrice,
                'precio_anterior' => $currentPrice->precio_actual ?? null,
                'ultima_actualizacion' => now(),
                'created_at' => now(),
            ]);
        }
    }

    // =========================================================================
    // MÉTODOS DE ACTUALIZACIÓN DE STOCK
    // =========================================================================

    /**
     * Actualiza stock en batch, solo si cambió
     */
    public function updateStockBatch(array $articles, int $providerIdDb): array
    {
        $stats = ['total' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => 0];

        DB::transaction(function () use ($articles, $providerIdDb, &$stats) {
            $updateData = [];

            foreach ($articles as $article) {
                try {
                    $dto = ProductoFactory::fromCVA($article);
                    $stats['total']++;

                    $providerProduct = $this->findProviderProductByDto($dto, $providerIdDb);
                    
                    if (!$providerProduct) {
                        $stats['errors']++;
                        continue;
                    }

                    // Verificar si cambió el stock
                    if ($providerProduct->stock != $dto->stock || $providerProduct->stock_cd != $dto->stockCD) {
                        $updateData[] = [
                            'id' => $providerProduct->id,
                            'proveedor_id' => $providerIdDb,
                            'producto_id' => $providerProduct->producto_id,
                            'stock' => $dto->stock,
                            'stock_cd' => $dto->stockCD,
                            'ultima_actualizacion' => now(),
                            'updated_at' => now(),
                        ];
                        $stats['updated']++;
                    } else {
                        $stats['unchanged']++;
                    }

                } catch (\Exception $e) {
                    Log::error("Error actualizando stock", [
                        'article' => $article['id'] ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);
                    $stats['errors']++;
                }
            }

            // Upsert masivo solo de los que cambiaron
            if (!empty($updateData)) {
                DB::table('proveedor_productos')->upsert(
                    $updateData,
                    ['id'], // Primary key
                    ['stock', 'stock_cd', 'ultima_actualizacion', 'updated_at']
                );
            }
        });

        return $stats;
    }

    /**
     * Actualiza stock de un solo producto
     */
    protected function updateSingleStock($dto, int $providerProductId): void
    {
        $providerProduct = ProveedorProducto::find($providerProductId);

        if (!$providerProduct) {
            return;
        }

        if ($providerProduct->stock != $dto->stock || $providerProduct->stock_cd != $dto->stockCD) {
            $providerProduct->update([
                'stock' => $dto->stock,
                'stock_cd' => $dto->stockCD,
                'ultima_actualizacion' => now(),
            ]);
        }
    }

    // =========================================================================
    // MÉTODOS DE ACTUALIZACIÓN DE PROMOCIONES
    // =========================================================================

    /**
     * Actualiza promociones en batch con detección de cambios
     */
    public function updatePromotionsBatch(array $articles, int $providerIdDb): array
    {
        $stats = ['total' => 0, 'created' => 0, 'updated_stock' => 0, 'unchanged' => 0, 'expired' => 0, 'errors' => 0];

        DB::transaction(function () use ($articles, $providerIdDb, &$stats) {
            foreach ($articles as $article) {
                try {
                    $dto = ProductoFactory::fromCVA($article);
                    $stats['total']++;

                    $providerProduct = $this->findProviderProductByDto($dto, $providerIdDb);
                    if (!$providerProduct) {
                        $stats['errors']++;
                        continue;
                    }

                    $lastPromo = DB::table('proveedor_producto_promociones')
                        ->where('proveedor_producto_id', $providerProduct->id)
                        ->latest('id') // Usamos ID para asegurar que es el registro más reciente
                        ->first();

                    if ($dto->esOferta || ($dto->descuentoTotal !== null && $dto->descuentoTotal > 0)) {
                        $newPromoData = $this->buildPromotionData($dto, $providerProduct->id);

                        // 1. Validar si la promoción base cambió (Precio, Descuento, Expiración)
                        if (!$lastPromo || $this->hasPromotionChanged($lastPromo, $newPromoData)) {
                            // Es una promoción nueva o distinta -> INSERT
                            DB::table('proveedor_producto_promociones')->insert($newPromoData);
                            
                            DB::table('proveedor_productos')
                                ->where('id', $providerProduct->id)
                                ->update(['en_oferta' => true]);
                                
                            $stats['created']++;
                        } 
                        // 2. Si es la misma promo, validar si cambió la cantidad disponible
                        elseif ((string)$lastPromo->disponible_en_promocion !== (string)$newPromoData['disponible_en_promocion']) {
                            // Misma promo pero cambió el stock disponible -> UPDATE
                            DB::table('proveedor_producto_promociones')
                                ->where('id', $lastPromo->id)
                                ->update([
                                    'disponible_en_promocion' => $newPromoData['disponible_en_promocion'],
                                    'ultima_actualizacion' => now(), // Si tienes este campo
                                    'updated_at' => now()
                                ]);
                                
                            $stats['updated_stock']++;
                        } 
                        else {
                            $stats['unchanged']++;
                        }
                    } else {
                        // No hay promo en DTO: si estaba marcado como oferta, limpiar flag
                        if ($providerProduct->en_oferta) {
                            DB::table('proveedor_productos')
                                ->where('id', $providerProduct->id)
                                ->update(['en_oferta' => false]);
                            $stats['expired']++;
                        }
                    }

                } catch (\Exception $e) {
                    Log::error("Error procesando promoción", [
                        'article' => $article['id'] ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);
                    $stats['errors']++;
                }
            }
        });

        return $stats;
    }
    /**
     * Actualiza promoción de un solo producto
     */
    protected function updateSinglePromotion($dto, int $providerProductId): void
    {
        $lastPromo = DB::table('proveedor_producto_promociones')
            ->where('proveedor_producto_id', $providerProductId)
            ->latest('created_at')
            ->first();

        if ($dto->esOferta || $dto->descuentoTotal !== null) {
            $newPromo = $this->buildPromotionData($dto, $providerProductId);

            if (!$lastPromo || $this->hasPromotionChanged($lastPromo, $newPromo)) {
                DB::table('proveedor_producto_promociones')->insert($newPromo);
                
                ProveedorProducto::where('id', $providerProductId)
                    ->update(['en_oferta' => true]);
            }
        } else {
            if ($lastPromo) {
                ProveedorProducto::where('id', $providerProductId)
                    ->update(['en_oferta' => false]);
            }
        }
    }

    // =========================================================================
    // MÉTODOS AUXILIARES - BÚSQUEDA Y CREACIÓN
    // =========================================================================

    /**
     * Busca o crea un producto
     */
    protected function findOrCreateProduct($dto): Producto
    {
        $uniqueKey = $this->getUniqueKey($dto);
        
        $this->ensureMasterDataExists([$dto]);

        return Producto::firstOrCreate(
            ['upc' => $uniqueKey],
            [
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
            ]
        );
    }

    /**
     * Busca o crea un provider_product
     */
    protected function findOrCreateProviderProduct($dto, int $productId, int $providerIdDb): ProveedorProducto
    {
        return ProveedorProducto::firstOrCreate(
            [
                'proveedor_id' => $providerIdDb,
                'producto_id' => $productId,
            ],
            [
                'proveedor_producto_id' => $dto->proveedorProductoId,
                'codigo_proveedor' => $dto->proveedorProductoCodigo,
                'moneda' => $dto->moneda,
                'stock' => $dto->stock,
                'stock_cd' => $dto->stockCD,
                'garantia' => $dto->garantia,
                'en_oferta' => $dto->enOferta,
                'ultima_actualizacion' => now(),
            ]
        );
    }

    /**
     * Busca provider_product por DTO
     */
    protected function findProviderProductByDto($dto, int $providerIdDb)
    {
        $uniqueKey = $this->getUniqueKey($dto);
        
        return DB::table('proveedor_productos as pp')
            ->join('productos as p', 'pp.producto_id', '=', 'p.id')
            ->where('pp.proveedor_id', $providerIdDb)
            ->where('p.upc', $uniqueKey)
            ->select('pp.*')
            ->first();
    }

    /**
     * Obtiene unique key del DTO
     */
    protected function getUniqueKey($dto): ?string
    {
        return $dto->upc ?? $dto->codigoBarras?? null;
    }

    /**
     * Filtra DTOs con unique key válido
     */
    protected function filterValidDtos(array $dtos): array
    {
        return array_filter($dtos, function($dto) {
            $uniqueKey = $this->getUniqueKey($dto);
            if (!$uniqueKey) {
                Log::warning("DTO sin unique key, se omite", [
                    'nombre' => $dto->nombre,
                    'proveedor_producto_id' => $dto->proveedorProductoId
                ]);
                return false;
            }
            return true;
        });
    }

    // =========================================================================
    // MÉTODOS DE UPSERT MASIVO (PARA INSERCIÓN INICIAL)
    // =========================================================================

    /**
     * Upsert masivo de productos
     */
    protected function upsertProducts(array $dtos): Collection
    {
        $productsData = [];
        
        foreach ($dtos as $dto) {
            $uniqueKey = $this->getUniqueKey($dto);
            
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

        if (!empty($productsData)) {
            Producto::upsert(
                $productsData,
                ['upc'],
                ['nombre', 'descripcion', 'descripcion_tecnica', 'categoria_id', 'sub_categoria_id', 
                 'familia_id', 'grupo_id', 'marca_id', 'codigo_fabricante', 'codigo_barras', 'updated_at']
            );
        }

        $upcs = array_column($productsData, 'upc');
        return Producto::whereIn('upc', $upcs)->get()->keyBy('upc');
    }

    /**
     * Upsert masivo de provider_products
     */
    protected function upsertProviderProducts(array $dtos, Collection $products, int $providerIdDb): Collection
    {
        $providerProductsData = [];
        
        foreach ($dtos as $dto) {
            $uniqueKey = $this->getUniqueKey($dto);
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

        if (!empty($providerProductsData)) {
            DB::table('proveedor_productos')->upsert(
                $providerProductsData,
                ['proveedor_id', 'producto_id'],
                ['proveedor_producto_id', 'codigo_proveedor', 'moneda', 
                 'stock', 'stock_cd', 'garantia', 'en_oferta', 'ultima_actualizacion', 'updated_at']
            );
        }

        return ProveedorProducto::where('proveedor_id', $providerIdDb)
            ->whereIn('producto_id', $products->pluck('id'))
            ->get()
            ->keyBy(fn($pp) => $pp->proveedor_id . '-' . $pp->producto_id);
    }

    /**
     * Upsert masivo de precios iniciales
     * Ahora maneja tanto inserciones como actualizaciones
     */
    protected function insertInitialPrices(array $dtos, Collection $providerProducts): void
    {
        $pricesData = [];
        
        foreach ($dtos as $dto) {
            $uniqueKey = $this->getUniqueKey($dto);
            $products = Producto::where('upc', $uniqueKey)->get();
            
            foreach ($products as $product) {
                $ppKey = $providerProducts->firstWhere('producto_id', $product->id);
                
                if (!$ppKey) continue;

                $pricesData[] = [
                    'proveedor_producto_id' => $ppKey->id,
                    'precio_actual' => $dto->precioActual,
                    'precio_anterior' => null,
                    'ultima_actualizacion' => now(),
                    'created_at' => now(),
                    'updated_at' => now(), // ✅ Agregado para upsert
                ];
            }
        }

        if (!empty($pricesData)) {
            // UPSERT: Si ya existe un registro con el mismo proveedor_producto_id,
            // actualiza el precio_actual y ultima_actualizacion
            DB::table('proveedor_producto_precios')->upsert(
                $pricesData,
                ['proveedor_producto_id'], // ✅ Unique constraint
                ['precio_actual', 'ultima_actualizacion', 'updated_at'] // ✅ Columnas a actualizar
            );
        }
    }

    /**
     * Upsert masivo de imágenes
     */
    protected function upsertImages(array $dtos, Collection $products): void
    {
        $imagesData = [];
        
        foreach ($dtos as $dto) {
            $uniqueKey = $this->getUniqueKey($dto);
            $product = $products[$uniqueKey] ?? null;
            
            if (!$product) continue;

            foreach ($dto->imagenes as $path) {
                $imagesData[] = [
                    'url_imagen' => $path,
                    'producto_id' => $product->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        if (!empty($imagesData)) {
            DB::table('producto_imagenes')->upsert(
                $imagesData,
                ['url_imagen', 'producto_id'],
                ['updated_at']
            );
        }
    }

    /**
     * Actualiza imágenes de un solo producto
     */
    protected function updateProductImages($dto, int $productId): void
    {
        if (empty($dto->imagenes)) {
            return;
        }

        $imagesData = [];
        foreach ($dto->imagenes as $path) {
            $imagesData[] = [
                'url_imagen' => $path,
                'producto_id' => $productId,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('producto_imagenes')->upsert(
            $imagesData,
            ['url_imagen', 'producto_id'],
            ['updated_at']
        );
    }

    /**
     * Insert masivo de promociones iniciales
     */
    protected function insertInitialPromotions(array $dtos, Collection $providerProducts): void
    {
        $promotionsData = [];
        
        foreach ($dtos as $dto) {
            if (!($dto->esOferta || $dto->descuentoTotal !== null)) {
                continue;
            }

            $uniqueKey = $this->getUniqueKey($dto);
            $products = Producto::where('upc', $uniqueKey)->get();
            
            foreach ($products as $product) {
                $ppKey = $providerProducts->firstWhere('producto_id', $product->id);
                
                if (!$ppKey) continue;

                $promotionsData[] = [
                    'proveedor_producto_id' => $ppKey->id,
                    'clave_promocion' => $dto->clavePromocion ?? 'PROMO-' . $ppKey->id, // ✅ Garantizar clave
                    'total_descuento' => $dto->descuentoTotal,
                    'moneda_descuento' => $dto->descuentoMoneda,
                    'precio_con_descuento' => $dto->descuentoPrecio,
                    'descuento_precio_moneda' => $dto->descuentoPrecioMoneda,
                    'descripcion_promocion' => $dto->promocionDescripcion,
                    'expiracion' => $dto->promocionExpiracion,
                    'disponible_en_promocion' => $dto->disponiblesEnPromocion,
                    'precio_oferta' => $dto->ofertaPrecio,
                    'precio_regular' => $dto->precioRegular,
                    'es_oferta' => $dto->esOferta,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        if (!empty($promotionsData)) {
            // ✅ UPSERT: Actualiza si existe la misma clave de promoción
            DB::table('proveedor_producto_promociones')->upsert(
                $promotionsData,
                ['proveedor_producto_id', 'clave_promocion'], // ✅ Composite key
                [
                    'total_descuento', 
                    'moneda_descuento', 
                    'precio_con_descuento',
                    'descuento_precio_moneda',
                    'descripcion_promocion',
                    'expiracion',
                    'disponible_en_promocion',
                    'precio_oferta',
                    'precio_regular',
                    'es_oferta',
                    'updated_at'
                ]
            );
        }
    }

    /**
     * Construye array de datos de promoción
     */
    protected function buildPromotionData($dto, int $providerProductId): array
    {
        return [
            'proveedor_producto_id' => $providerProductId,
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

    /**
     * Compara si una promoción cambió
     */
    protected function hasPromotionChanged($lastPromo, array $newPromo): bool
    {
        return $lastPromo->total_descuento != $newPromo['total_descuento']
            || $lastPromo->precio_con_descuento != $newPromo['precio_con_descuento']
            || $lastPromo->clave_promocion != $newPromo['clave_promocion']
            || $lastPromo->precio_oferta != $newPromo['precio_oferta']
            || $lastPromo->expiracion != $newPromo['expiracion'];
    }

    // =========================================================================
    // MÉTODOS DE API CVA
    // =========================================================================

    /**
     * Obtiene token de CVA
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
     * Obtiene artículos de una página específica
     */
    protected function fetchCVAArticles(string $token, array $filters, int $page): array
    {
        $baseUrl = config('services.cva.api_url');
        $queryParams = array_merge($filters, ['page' => $page]);

        $response = Http::withToken($token)
            ->get($baseUrl . 'catalogo_clientes/lista_precios', $queryParams);

        $paginacion = $response->json('paginacion');

        if ($response->unauthorized()) {
            Cache::forget('cva_bearer_token');
            throw new \Exception("Token expirado. Intenta de nuevo.");
        }

        if ($response->failed()) {
            throw new \Exception("CVA API Error: " . $response->status());
        }


        return [
           'articulos' =>  $response->collect('articulos'),
           'paginacion' =>  $paginacion
        ];
    }

    /**
     * Obtiene TODOS los artículos paginando automáticamente
     */
    protected function fetchAllCVAArticles(string $token, array $filters): Collection
    {
        $allArticles = collect();
        $page = 1;
        
        do {
            $articles = $this->fetchCVAArticles($token, $filters, $page);
            $allArticles = $allArticles->merge($articles);
            $page++;
            
            // Evitar loop infinito
            if ($page > 1000) {
                Log::warning("Más de 1000 páginas en CVA, deteniendo...");
                break;
            }
        } while ($articles->isNotEmpty());

        return $allArticles;
    }

    /**
     * Construye respuesta de paginación
     */
    protected function buildPaginationResponse(int $page, int $count,int $totalPages): ?array
    {
        // Aquí podrías obtener info de paginación de la API
        return [
            'current' => $page,
            'total_paginas' => $totalPages,
            'productos_procesados' => $count
        ];
    }

    // =========================================================================
    // MÉTODOS DE RELACIONES MAESTRAS
    // =========================================================================

    /**
     * Pre-carga todas las relaciones maestras en memoria
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
     * Asegura que todas las relaciones maestras existan
     */
    protected function ensureMasterDataExists(array $dtos): void
    {
        $categories = [];
        $subcategories = [];
        $families = [];
        $groups = [];
        $brands = [];

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

        $this->upsertMasterData(Categoria::class, $categories, 'categories');
        $this->upsertMasterData(SubCategoria::class, $subcategories, 'subcategories');
        $this->upsertMasterData(Familia::class, $families, 'families');
        $this->upsertMasterData(Grupo::class, $groups, 'groups');
        $this->upsertMasterData(Marca::class, $brands, 'brands');
    }

    /**
     * Upsert masivo de relaciones maestras
     */
    protected function upsertMasterData(string $modelClass, array $names, string $cacheKey): void
    {
        if (empty($names)) {
            return;
        }

        $data = array_map(fn($name) => [
            'nombre' => $name,
            'created_at' => now(),
            'updated_at' => now()
        ], array_keys($names));

        $modelClass::upsert($data, ['nombre'], ['updated_at']);
        $this->lookupCache[$cacheKey] = $modelClass::pluck('id', 'nombre')->toArray();
    }
}