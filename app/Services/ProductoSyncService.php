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
use Illuminate\Support\Facades\Log;
use Spatie\LaravelData\DataCollection;

/**
 * Servicio de Sincronización de Productos
 * 
 * Mejoras implementadas:
 * Transacciones con bloqueos pesimistas
 * Manejo automático de deadlocks con reintentos
 * Bloqueos granulares para mejor concurrencia
 * Fix para grupos con barras escapadas (\/)
 * Fallback a "General" para evitar NULLs
 * 
 * Estrategias disponibles:
 * 1. Sincronización inicial completa (initialSyncCVA)
 * 2. Actualización solo de precios (updatePricesCVA)
 * 3. Actualización solo de stock (updateStockCVA)
 * 4. Actualización solo de promociones (updatePromotionsCVA)
 * 5. Actualización completa de un artículo (updateSingleArticleCVA)
 */
class ProductoSyncService
{
    // =========================================================================
    // CONFIGURACIÓN Y CONSTANTES
    // =========================================================================

    protected const BATCH_SIZE = 500;
    protected const CACHE_TTL = 3600;
    protected const MAX_RETRIES = 3;
    protected const RETRY_DELAY_MS = 100;

    protected array $cacheDeBusqueda = [
        'categorias' => [],
        'subcategorias' => [],
        'familias' => [],
        'grupos' => [],
        'marcas' => [],
    ];

    public function __construct(
        private readonly CvaRepository $apiCva
    ) {
        // Asegurar que existan registros "General" por defecto
        $this->asegurarRegistrosGeneralesExisten();
    }

    // =========================================================================
    // MÉTODOS PÚBLICOS - SINCRONIZACIÓN INICIAL
    // =========================================================================

    /**
     * SINCRONIZACIÓN INICIAL COMPLETA
     */
    public function initialSyncCVA(int $proveedorIdBd, array $filtros = [], int $pagina = 1): ?array
    {
        $respuesta = $this->apiCva->getProductsGeneral($filtros, $pagina);
        
        if ($respuesta->articulos->count() === 0) {
            return null;
        }

        $this->precargarRelacionesMaestras();
        
        $productosDto = collect();
        foreach ($respuesta->articulos as $articulo) {
            $productosDto->push(ProductoFactory::fromCVA($articulo));
        }

        $this->persistirBatchInicialConReintentos($productosDto->all(), $proveedorIdBd);

        return $this->construirRespuestaPaginacion(
            $pagina, 
            $respuesta->articulos->count(),
            $respuesta->paginacion->totalPaginas
        );
    }

    // =========================================================================
    // MÉTODOS PÚBLICOS - ACTUALIZACIONES ESPECÍFICAS
    // =========================================================================

    /**
     * ACTUALIZACIÓN SOLO DE PRECIOS
     */
    public function updatePricesCVA(int $proveedorIdBd, array $filtros = []): array
    {
        $respuesta = $this->apiCva->getProductsGeneral($filtros, 1);
        
        $estadisticas = [
            'total' => 0,
            'actualizados' => 0,
            'sin_cambios' => 0,
            'errores' => 0,
            'deadlocks_recuperados' => 0
        ];

        $articulosArray = $respuesta->articulos->toArray();
        
        foreach (array_chunk($articulosArray, self::BATCH_SIZE) as $chunk) {
            $articulosChunk = ArticuloData::collection($chunk);
            
            $resultado = $this->actualizarPreciosBatchConReintentos($articulosChunk, $proveedorIdBd);
            
            $estadisticas['total'] += $resultado['total'];
            $estadisticas['actualizados'] += $resultado['actualizados'];
            $estadisticas['sin_cambios'] += $resultado['sin_cambios'];
            $estadisticas['errores'] += $resultado['errores'];
            $estadisticas['deadlocks_recuperados'] += $resultado['deadlocks_recuperados'] ?? 0;
        }

        return $estadisticas;
    }

    /**
     * ACTUALIZACIÓN SOLO DE STOCK
     */
    public function updateStockCVA(int $proveedorIdBd, array $filtros = []): array
    {
        $respuesta = $this->apiCva->getProductsGeneral($filtros);
        
        $estadisticas = [
            'total' => 0,
            'actualizados' => 0,
            'sin_cambios' => 0,
            'errores' => 0,
            'deadlocks_recuperados' => 0
        ];

        $articulosArray = $respuesta->articulos->toArray();
        
        foreach (array_chunk($articulosArray, self::BATCH_SIZE) as $chunk) {
            $articulosChunk = ArticuloData::collection($chunk);
            
            $resultado = $this->actualizarStockBatchConReintentos($articulosChunk, $proveedorIdBd);
            
            $estadisticas['total'] += $resultado['total'];
            $estadisticas['actualizados'] += $resultado['actualizados'];
            $estadisticas['sin_cambios'] += $resultado['sin_cambios'];
            $estadisticas['errores'] += $resultado['errores'];
            $estadisticas['deadlocks_recuperados'] += $resultado['deadlocks_recuperados'] ?? 0;
        }

        return $estadisticas;
    }

    /**
     * ACTUALIZACIÓN SOLO DE PROMOCIONES
     */
    public function updatePromotionsCVA(int $proveedorIdBd, array $filtros = []): array
    {
        $respuesta = $this->apiCva->getProductsGeneral($filtros);
        
        $estadisticas = [
            'total' => 0,
            'creadas' => 0,
            'stock_actualizado' => 0,
            'sin_cambios' => 0,
            'expiradas' => 0,
            'errores' => 0,
            'deadlocks_recuperados' => 0
        ];

        $articulosArray = $respuesta->articulos->toArray();
        
        foreach (array_chunk($articulosArray, self::BATCH_SIZE) as $chunk) {
            $articulosChunk = ArticuloData::collection($chunk);
            
            $resultado = $this->actualizarPromocionesBatchConReintentos($articulosChunk, $proveedorIdBd);
            
            $estadisticas['total'] += $resultado['total'];
            $estadisticas['creadas'] += $resultado['creadas'];
            $estadisticas['stock_actualizado'] += $resultado['stock_actualizado'];
            $estadisticas['sin_cambios'] += $resultado['sin_cambios'];
            $estadisticas['expiradas'] += $resultado['expiradas'];
            $estadisticas['errores'] += $resultado['errores'];
            $estadisticas['deadlocks_recuperados'] += $resultado['deadlocks_recuperados'] ?? 0;
        }

        return $estadisticas;
    }

    /**
     * ACTUALIZACIÓN COMPLETA DE UN SOLO ARTÍCULO
     */
    public function updateSingleArticleCVA(int $proveedorIdBd, string $idArticulo): array
    {
        $filtros = ['clave' => $idArticulo,'upc' => true,'promos' => true,'MonedaPesos' => true];
        $articulo = $this->apiCva->getSingleProductByClave($filtros);

        if (!isset($articulo) || is_null($articulo)) {
            return [
                'exito' => false,
                'error' => "Artículo {$idArticulo} no encontrado en CVA"
            ];
        }

        $dto = ProductoFactory::fromCVA($articulo);

        return $this->ejecutarConReintentos(function () use ($dto, $proveedorIdBd, $idArticulo) {
            DB::transaction(function () use ($dto, $proveedorIdBd) {
                $producto = $this->buscarOCrearProductoConBloqueo($dto);
                $proveedorProducto = $this->buscarOCrearProveedorProductoConBloqueo($dto, $producto->id, $proveedorIdBd);
                $this->actualizarPrecioIndividualConBloqueo($dto, $proveedorProducto->id);
                $this->actualizarStockIndividualConBloqueo($dto, $proveedorProducto->id);
                $this->actualizarPromocionIndividualConBloqueo($dto, $proveedorProducto->id);
            }, attempts: 5);

            return [
                'exito' => true,
                'id_articulo' => $idArticulo,
                'mensaje' => 'Artículo actualizado correctamente'
            ];
        }, self::MAX_RETRIES, function ($e) use ($idArticulo) {
            Log::error("Error actualizando artículo {$idArticulo}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'exito' => false,
                'id_articulo' => $idArticulo,
                'error' => $e->getMessage()
            ];
        });
    }

    // =========================================================================
    // MÉTODOS PÚBLICOS - CONSULTAS API
    // =========================================================================

    public function obtenerUnoPorClave(array $filtros)
    {
        return $this->apiCva->getSingleProductByClave($filtros);
    }

    public function obtenerProductosGenerales(array $filtros = [], int $pagina = 1)
    {
        return $this->apiCva->getProductsGeneral($filtros, $pagina);
    }

    // =========================================================================
    // MÉTODOS CON REINTENTOS Y MANEJO DE DEADLOCKS
    // =========================================================================

    /**
     * Ejecuta una operación con reintentos automáticos en caso de deadlock
     */
    protected function ejecutarConReintentos(callable $operacion, int $maxIntentos = self::MAX_RETRIES, ?callable $onError = null)
    {
        $intento = 0;
        
        while ($intento < $maxIntentos) {
            try {
                return $operacion();
            } catch (\Exception $e) {
                $intento++;
                
                if ($this->esDeadlock($e) && $intento < $maxIntentos) {
                    $delay = self::RETRY_DELAY_MS * pow(2, $intento - 1);
                    usleep($delay * 1000);
                    
                    Log::warning("Deadlock detectado, reintentando", [
                        'intento' => $intento,
                        'max_intentos' => $maxIntentos,
                        'delay_ms' => $delay
                    ]);
                    
                    continue;
                }
                
                if ($onError) {
                    return $onError($e);
                }
                throw $e;
            }
        }
    }

    /**
     * Detecta si una excepción es un deadlock
     */
    protected function esDeadlock(\Exception $e): bool
    {
        $mensaje = $e->getMessage();
        
        return str_contains($mensaje, 'Deadlock') ||
               str_contains($mensaje, 'try restarting transaction') ||
               $e->getCode() === '40001' ||
               $e->getCode() === 1213;
    }

    protected function persistirBatchInicialConReintentos(array $productosDto, int $proveedorIdBd): void
    {
        $this->ejecutarConReintentos(function () use ($productosDto, $proveedorIdBd) {
            $this->persistirBatchInicial($productosDto, $proveedorIdBd);
        }, self::MAX_RETRIES, function ($e) {
            Log::error("Error en persistirBatchInicial después de reintentos", [
                'error' => $e->getMessage()
            ]);
            throw $e;
        });
    }

    protected function actualizarPreciosBatchConReintentos(DataCollection $articulos, int $proveedorIdBd): array
    {
        return $this->ejecutarConReintentos(
            fn() => $this->actualizarPreciosBatch($articulos, $proveedorIdBd),
            self::MAX_RETRIES,
            function ($e) {
                Log::error("Error en actualizarPreciosBatch", ['error' => $e->getMessage()]);
                return [
                    'total' => 0,
                    'actualizados' => 0,
                    'sin_cambios' => 0,
                    'errores' => 1,
                    'deadlocks_recuperados' => 0
                ];
            }
        );
    }

    protected function actualizarStockBatchConReintentos(DataCollection $articulos, int $proveedorIdBd): array
    {
        return $this->ejecutarConReintentos(
            fn() => $this->actualizarStockBatch($articulos, $proveedorIdBd),
            self::MAX_RETRIES,
            function ($e) {
                Log::error("Error en actualizarStockBatch", ['error' => $e->getMessage()]);
                return [
                    'total' => 0,
                    'actualizados' => 0,
                    'sin_cambios' => 0,
                    'errores' => 1,
                    'deadlocks_recuperados' => 0
                ];
            }
        );
    }

    protected function actualizarPromocionesBatchConReintentos(DataCollection $articulos, int $proveedorIdBd): array
    {
        return $this->ejecutarConReintentos(
            fn() => $this->actualizarPromocionesBatch($articulos, $proveedorIdBd),
            self::MAX_RETRIES,
            function ($e) {
                Log::error("Error en actualizarPromocionesBatch", ['error' => $e->getMessage()]);
                return [
                    'total' => 0,
                    'creadas' => 0,
                    'stock_actualizado' => 0,
                    'sin_cambios' => 0,
                    'expiradas' => 0,
                    'errores' => 1,
                    'deadlocks_recuperados' => 0
                ];
            }
        );
    }

    // =========================================================================
    // MÉTODOS PÚBLICOS - ACTUALIZACIÓN DE PRECIOS CON BLOQUEOS
    // =========================================================================

    public function actualizarPreciosBatch(DataCollection $articulos, int $proveedorIdBd): array
    {
        $estadisticas = [
            'total' => 0, 
            'actualizados' => 0, 
            'sin_cambios' => 0, 
            'errores' => 0
        ];

        DB::transaction(function () use ($articulos, $proveedorIdBd, &$estadisticas) {
            foreach ($articulos as $articulo) {
                try {
                    $dto = ProductoFactory::fromCVA($articulo);
                    $estadisticas['total']++;

                    $proveedorProducto = DB::table('proveedor_productos as pp')
                        ->join('productos as p', 'pp.producto_id', '=', 'p.id')
                        ->where('pp.proveedor_id', $proveedorIdBd)
                        ->where('p.upc', $this->obtenerClaveUnica($dto))
                        ->select('pp.*')
                        ->lockForUpdate()
                        ->first();
                    
                    if (!$proveedorProducto) {
                        $estadisticas['errores']++;
                        continue;
                    }

                    $registroPrecioActual = DB::table('proveedor_producto_precios')
                        ->where('proveedor_producto_id', $proveedorProducto->id)
                        ->lockForUpdate()
                        ->first();

                    $precioNuevo = $dto->precioActual;

                    if (!$registroPrecioActual || $registroPrecioActual->precio_actual != $precioNuevo) {
                        
                        if ($registroPrecioActual) {
                            DB::table('proveedor_producto_precios')
                                ->where('proveedor_producto_id', $proveedorProducto->id)
                                ->update([
                                    'precio_anterior' => $registroPrecioActual->precio_actual,
                                    'precio_actual' => $precioNuevo,
                                    'ultima_actualizacion' => now(),
                                    'updated_at' => now(),
                                ]);
                        } else {
                            DB::table('proveedor_producto_precios')->insert([
                                'proveedor_producto_id' => $proveedorProducto->id,
                                'precio_anterior' => null,
                                'precio_actual' => $precioNuevo,
                                'ultima_actualizacion' => now(),
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }

                        $estadisticas['actualizados']++;
                    } else {
                        $estadisticas['sin_cambios']++;
                    }

                } catch (\Exception $e) {
                    Log::error("Error actualizando precio", [
                        'articulo_id' => $articulo->id ?? 'desconocido',
                        'error' => $e->getMessage()
                    ]);
                    $estadisticas['errores']++;
                }
            }
        }, attempts: 5);

        return $estadisticas;
    }

    // =========================================================================
    // MÉTODOS PÚBLICOS - ACTUALIZACIÓN DE STOCK CON BLOQUEOS
    // =========================================================================

    public function actualizarStockBatch(DataCollection $articulos, int $proveedorIdBd): array
    {
        $estadisticas = [
            'total' => 0, 
            'actualizados' => 0, 
            'sin_cambios' => 0, 
            'errores' => 0
        ];

        DB::transaction(function () use ($articulos, $proveedorIdBd, &$estadisticas) {
            $idsParaBloquear = [];
            $datosParaActualizar = [];

            foreach ($articulos as $articulo) {
                try {
                    $dto = ProductoFactory::fromCVA($articulo);
                    $estadisticas['total']++;

                    $proveedorProducto = DB::table('proveedor_productos as pp')
                        ->join('productos as p', 'pp.producto_id', '=', 'p.id')
                        ->where('pp.proveedor_id', $proveedorIdBd)
                        ->where('p.upc', $this->obtenerClaveUnica($dto))
                        ->select('pp.*')
                        ->first();
                    
                    if (!$proveedorProducto) {
                        $estadisticas['errores']++;
                        continue;
                    }

                    if ($proveedorProducto->stock != $dto->stock || 
                        $proveedorProducto->stock_cd != $dto->stockCD) {
                        
                        $idsParaBloquear[] = $proveedorProducto->id;
                        $datosParaActualizar[$proveedorProducto->id] = [
                            'id' => $proveedorProducto->id,
                            'proveedor_id' => $proveedorIdBd,
                            'producto_id' => $proveedorProducto->producto_id,
                            'proveedor_producto_id' => $proveedorProducto->proveedor_producto_id,
                            'stock' => $dto->stock,
                            'codigo_proveedor' => $proveedorProducto->codigo_proveedor,
                            'moneda' => $proveedorProducto->moneda,
                            'garantia' => $proveedorProducto->garantia,
                            'stock_cd' => $dto->stockCD,
                            'ultima_actualizacion' => now(),
                            'updated_at' => now(),
                        ];
                        $estadisticas['actualizados']++;
                    } else {
                        $estadisticas['sin_cambios']++;
                    }

                } catch (\Exception $e) {
                    Log::error("Error procesando stock", [
                        'articulo_id' => $articulo->id ?? 'desconocido',
                        'error' => $e->getMessage()
                    ]);
                    $estadisticas['errores']++;
                }
            }

            if (!empty($idsParaBloquear)) {
                sort($idsParaBloquear);
                
                DB::table('proveedor_productos')
                    ->whereIn('id', $idsParaBloquear)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                DB::table('proveedor_productos')->upsert(
                    array_values($datosParaActualizar),
                    ['id'],
                    ['stock', 'stock_cd', 'ultima_actualizacion', 'updated_at']
                );
            }
        }, attempts: 5);

        return $estadisticas;
    }

    // =========================================================================
    // MÉTODOS PÚBLICOS - ACTUALIZACIÓN DE PROMOCIONES CON BLOQUEOS
    // =========================================================================

    // =========================================================================
    public function actualizarPromocionesBatch(DataCollection $articulos, int $proveedorIdBd): array
    {
        $estadisticas = [
            'total' => 0, 
            'creadas' => 0, 
            'stock_actualizado' => 0, 
            'sin_cambios' => 0, 
            'expiradas' => 0, 
            'errores' => 0
        ];

        DB::transaction(function () use ($articulos, $proveedorIdBd, &$estadisticas) {
            foreach ($articulos as $articulo) {
                try {
                    $dto = ProductoFactory::fromCVA($articulo);
                    $estadisticas['total']++;

                    $proveedorProducto = DB::table('proveedor_productos as pp')
                        ->join('productos as p', 'pp.producto_id', '=', 'p.id')
                        ->where('pp.proveedor_id', $proveedorIdBd)
                        ->where('p.upc', $this->obtenerClaveUnica($dto))
                        ->select('pp.*')
                        ->lockForUpdate()
                        ->first();

                    if (!$proveedorProducto) {
                        $estadisticas['errores']++;
                        continue;
                    }

                    $ultimaPromocion = DB::table('proveedor_producto_promociones')
                        ->where('proveedor_producto_id', $proveedorProducto->id)
                        ->orderBy('id', 'desc')
                        ->lockForUpdate()
                        ->first();

                    if ($dto->esOferta) {
                        $nuevaPromocionDatos = $this->construirDatosPromocion($dto, $proveedorProducto->id);

                        if (!$ultimaPromocion || $this->promocionHaCambiado($ultimaPromocion, $nuevaPromocionDatos)) {
                            // Si había una promoción anterior diferente, marcarla como expirada
                            if ($ultimaPromocion) {
                                DB::table('proveedor_producto_promociones')
                                    ->where('id', $ultimaPromocion->id)
                                    ->update([
                                        'en_oferta' => false,
                                        'updated_at' => now()
                                    ]);
                                $estadisticas['expiradas']++;
                            }
                        // Crear nueva promoción
                            DB::table('proveedor_producto_promociones')->insert($nuevaPromocionDatos);
                            
                            // Marcar producto como en oferta
                            DB::table('proveedor_productos')
                                ->where('id', $proveedorProducto->id)
                                ->update(['en_oferta' => true]);
                                
                            $estadisticas['creadas']++;
                        } 
                        else{                            // Actualizar solo stock de promoción existente
                            DB::table('proveedor_producto_promociones')
                                ->where('id', $ultimaPromocion->id)
                               ->update([
                                    'total_descuento' => $nuevaPromocionDatos['total_descuento'],
                                    'precio_con_descuento' => $nuevaPromocionDatos['precio_con_descuento'],
                                    'disponible_en_promocion' => $nuevaPromocionDatos['disponible_en_promocion'],
                                    'updated_at' => now()
                                ]);
                                
                            $estadisticas['stock_actualizado']++;
                        }
                    } else {
                        // El producto YA NO es oferta
                        if ($proveedorProducto->en_oferta) {
                            // Marcar la última promoción como inactiva/expirada
                            if ($ultimaPromocion) {
                                DB::table('proveedor_producto_promociones')
                                    ->where('id', $ultimaPromocion->id)
                                    ->update([
                                        'en_oferta' => false,
                                        'updated_at' => now()
                                    ]);
                            }
                            
                            // Marcar producto como NO en oferta
                            DB::table('proveedor_productos')
                                ->where('id', $proveedorProducto->id)
                                ->update(['en_oferta' => false]);
                                
                            $estadisticas['expiradas']++;
                        }
                    }

                } catch (\Exception $e) {
                    Log::error("Error procesando promoción", [
                        'articulo_id' => $articulo->id ?? 'desconocido',
                        'error' => $e->getMessage()
                    ]);
                    $estadisticas['errores']++;
                }
            }
        }, attempts: 5);

        return $estadisticas;
    }

    // =========================================================================
    // MÉTODOS PROTEGIDOS - PROCESAMIENTO DE BATCH INICIAL
    // =========================================================================

    protected function persistirBatchInicial(array $productosDto, int $proveedorIdBd): void
    {
        if (empty($productosDto)) {
            return;
        }

        DB::transaction(function () use ($productosDto, $proveedorIdBd) {
            $this->asegurarDatosMaestrosExisten($productosDto);
            $dtosValidos = $this->filtrarDtosValidos($productosDto);
            $productos = $this->upsertProductos($dtosValidos);
            $proveedorProductos = $this->upsertProveedorProductos($dtosValidos, $productos, $proveedorIdBd);
            $this->insertarPreciosIniciales($dtosValidos, $proveedorProductos);
            $this->upsertImagenes($dtosValidos, $productos);
            $this->insertarPromocionesIniciales($dtosValidos, $proveedorProductos);
        }, attempts: 5);
    }

    // =========================================================================
    // MÉTODOS CON BLOQUEO INDIVIDUAL
    // =========================================================================

    protected function buscarOCrearProductoConBloqueo(ProductoData $dto): Producto
    {
        $claveUnica = $this->obtenerClaveUnica($dto);
        
        $this->asegurarDatosMaestrosExisten([$dto]);

        $producto = Producto::where('upc', $claveUnica)
            ->lockForUpdate()
            ->first();

        if ($producto) {
            return $producto;
        }

        $nombreGrupo = $this->procesarNombreGrupo($dto->grupoNombre);

        return Producto::create([
            'upc' => $claveUnica,
            'nombre' => $dto->nombre,
            'descripcion' => $dto->descripcion,
            'descripcion_tecnica' => $dto->descripcionTecnica,
            'categoria_id' => $this->cacheDeBusqueda['categorias'][$dto->categoriaNombre] ?? null,
            'sub_categoria_id' => $this->cacheDeBusqueda['subcategorias'][$dto->subcategoriaNombre ?? 'General'] ?? null,
            'familia_id' => $this->cacheDeBusqueda['familias'][$dto->familiaNombre ?? 'General'] ?? null,
            'grupo_id' => $this->cacheDeBusqueda['grupos'][$nombreGrupo] ?? null,
            'marca_id' => $this->cacheDeBusqueda['marcas'][$dto->marcaNombre ?? 'General'] ?? null,
            'codigo_fabricante' => $dto->codigoFabricante,
            'codigo_barras' => $dto->codigoBarras,
        ]);
    }

    protected function buscarOCrearProveedorProductoConBloqueo(ProductoData $dto, int $productoId, int $proveedorIdBd): ProveedorProducto
    {
        $proveedorProducto = ProveedorProducto::where('proveedor_id', $proveedorIdBd)
            ->where('producto_id', $productoId)
            ->lockForUpdate()
            ->first();

        if ($proveedorProducto) {
            return $proveedorProducto;
        }

        return ProveedorProducto::create([
            'proveedor_id' => $proveedorIdBd,
            'producto_id' => $productoId,
            'proveedor_producto_id' => $dto->proveedorProductoId,
            'codigo_proveedor' => $dto->proveedorProductoCodigo,
            'moneda' => $dto->moneda,
            'stock' => $dto->stock,
            'stock_cd' => $dto->stockCD,
            'garantia' => $dto->garantia,
            'en_oferta' => $dto->enOferta,
            'ultima_actualizacion' => now(),
        ]);
    }

    protected function actualizarPrecioIndividualConBloqueo(ProductoData $dto, int $proveedorProductoId): void
    {
        $registroPrecio = DB::table('proveedor_producto_precios')
            ->where('proveedor_producto_id', $proveedorProductoId)
            ->lockForUpdate()
            ->first();

        if ($registroPrecio) {
            if ($registroPrecio->precio_actual != $dto->precioActual) {
                DB::table('proveedor_producto_precios')
                    ->where('proveedor_producto_id', $proveedorProductoId)
                    ->update([
                        'precio_anterior' => $registroPrecio->precio_actual,
                        'precio_actual' => $dto->precioActual,
                        'ultima_actualizacion' => now(),
                        'updated_at' => now(),
                    ]);
            }
        } else {
            DB::table('proveedor_producto_precios')->insert([
                'proveedor_producto_id' => $proveedorProductoId,
                'precio_actual' => $dto->precioActual,
                'precio_anterior' => null,
                'ultima_actualizacion' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    protected function actualizarStockIndividualConBloqueo(ProductoData $dto, int $proveedorProductoId): void
    {
        $proveedorProducto = ProveedorProducto::where('id', $proveedorProductoId)
            ->lockForUpdate()
            ->first();

        if ($proveedorProducto && 
            ($proveedorProducto->stock != $dto->stock || $proveedorProducto->stock_cd != $dto->stockCD)) {
            
            $proveedorProducto->update([
                'stock' => $dto->stock,
                'stock_cd' => $dto->stockCD,
                'ultima_actualizacion' => now(),
            ]);
        }
    }

    protected function actualizarPromocionIndividualConBloqueo(ProductoData $dto, int $proveedorProductoId): void
    {
        $ultimaPromocion = DB::table('proveedor_producto_promociones')
            ->where('proveedor_producto_id', $proveedorProductoId)
            ->orderBy('created_at', 'desc')
            ->lockForUpdate()
            ->first();

        if ($dto->esOferta || $dto->descuentoTotal !== null) {
            $nuevaPromocion = $this->construirDatosPromocion($dto, $proveedorProductoId);

            if (!$ultimaPromocion || $this->promocionHaCambiado($ultimaPromocion, $nuevaPromocion)) {
                DB::table('proveedor_producto_promociones')->insert($nuevaPromocion);
                
                ProveedorProducto::where('id', $proveedorProductoId)
                    ->update(['en_oferta' => true]);
            }
        } else {
            if ($ultimaPromocion) {
                ProveedorProducto::where('id', $proveedorProductoId)
                    ->update(['en_oferta' => false]);
            }
        }
    }

    // =========================================================================
    // MÉTODOS PROTEGIDOS - BÚSQUEDA Y CREACIÓN
    // =========================================================================

    protected function buscarOCrearProducto(ProductoData $dto): Producto
    {
        return $this->buscarOCrearProductoConBloqueo($dto);
    }

    protected function buscarOCrearProveedorProducto(ProductoData $dto, int $productoId, int $proveedorIdBd): ProveedorProducto
    {
        return $this->buscarOCrearProveedorProductoConBloqueo($dto, $productoId, $proveedorIdBd);
    }

    protected function buscarProveedorProductoPorDto(ProductoData $dto, int $proveedorIdBd)
    {
        $claveUnica = $this->obtenerClaveUnica($dto);
        
        return DB::table('proveedor_productos as pp')
            ->join('productos as p', 'pp.producto_id', '=', 'p.id')
            ->where('pp.proveedor_id', $proveedorIdBd)
            ->where('p.upc', $claveUnica)
            ->select('pp.*')
            ->first();
    }

    protected function obtenerClaveUnica(ProductoData $dto): ?string
    {
        return $dto->upc ?? $dto->codigoBarras ?? null;
    }

    protected function filtrarDtosValidos(array $productosDto): array
    {
        return array_filter($productosDto, function($dto) {
            $claveUnica = $this->obtenerClaveUnica($dto);
            if (!$claveUnica) {
                Log::warning("DTO sin clave única, se omite", [
                    'nombre' => $dto->nombre,
                    'proveedor_producto_id' => $dto->proveedorProductoId
                ]);
                return false;
            }
            return true;
        });
    }

    /**
     * Procesa el nombre del grupo manejando barras escapadas
     * 
     * Casos que maneja:
     * 1. "SOPORTES Y BASES P\/TV\/ PROYECTORES\/..." → "SOPORTES Y BASES P"
     * 2. "Monitores / Pantallas" → "Monitores"
     * 3. null → "General"
     * 4. "" → "General"
     * 
     * @param string|null $grupoNombre Nombre del grupo desde la API
     * @return string Nombre procesado o "General" como fallback
     */
    protected function procesarNombreGrupo(?string $grupoNombre): string
    {
        // Si es null o vacío, retornar "General"
        if (empty($grupoNombre)) {
            return 'General';
        }
        
        // Reemplazar barras escapadas \/ por barras normales /
        $nombreLimpio = str_replace('\/', '/', $grupoNombre);
        
        // Dividir por / y tomar solo la primera parte
        $partes = explode('/', $nombreLimpio);
        $nombreGrupo = trim($partes[0]);
        
        // Si después de limpiar queda vacío, usar "General"
        if (empty($nombreGrupo)) {
            return 'General';
        }
        
        // Limitar longitud (seguridad por si la columna tiene límite)
        if (strlen($nombreGrupo) > 255) {
            $nombreGrupo = substr($nombreGrupo, 0, 255);
        }
        
        return $nombreGrupo;
    }

    // =========================================================================
    // MÉTODOS PROTEGIDOS - UPSERT MASIVO
    // =========================================================================

    protected function upsertProductos(array $productosDto): Collection
    {
        $datosProductos = [];
        
        foreach ($productosDto as $dto) {
            $claveUnica = $this->obtenerClaveUnica($dto);
            
            // ✅ Procesar nombre de grupo correctamente
            $nombreGrupo = $this->procesarNombreGrupo($dto->grupoNombre);
            
            $datosProductos[] = [
                'upc' => $claveUnica,
                'nombre' => $dto->nombre,
                'descripcion' => $dto->descripcion,
                'descripcion_tecnica' => $dto->descripcionTecnica,
                'categoria_id' => $this->cacheDeBusqueda['categorias'][$dto->categoriaNombre] ?? null,
                'sub_categoria_id' => $this->cacheDeBusqueda['subcategorias'][$dto->subcategoriaNombre ?? 'General'] ?? null,
                'familia_id' => $this->cacheDeBusqueda['familias'][$dto->familiaNombre ?? 'General'] ?? null,
                'grupo_id' => $this->cacheDeBusqueda['grupos'][$nombreGrupo] ?? null,
                'marca_id' => $this->cacheDeBusqueda['marcas'][$dto->marcaNombre ?? 'General'] ?? null,
                'codigo_fabricante' => $dto->codigoFabricante,
                'codigo_barras' => $dto->codigoBarras,
                'updated_at' => now(),
                'created_at' => now(),
            ];
        }

        if (!empty($datosProductos)) {
            Producto::upsert(
                $datosProductos,
                ['upc'],
                ['nombre', 'descripcion', 'descripcion_tecnica', 'categoria_id', 'sub_categoria_id', 
                 'familia_id', 'grupo_id', 'marca_id', 'codigo_fabricante', 'codigo_barras', 'updated_at']
            );
        }

        $upcs = array_column($datosProductos, 'upc');
        return Producto::whereIn('upc', $upcs)->get()->keyBy('upc');
    }

    protected function upsertProveedorProductos(array $productosDto, Collection $productos, int $proveedorIdBd): Collection
    {
        $datosProveedorProductos = [];
        
        foreach ($productosDto as $dto) {
            $claveUnica = $this->obtenerClaveUnica($dto);
            $producto = $productos[$claveUnica] ?? null;
            
            if (!$producto) continue;

            $datosProveedorProductos[] = [
                'proveedor_id' => $proveedorIdBd,
                'producto_id' => $producto->id,
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

        if (!empty($datosProveedorProductos)) {
            DB::table('proveedor_productos')->upsert(
                $datosProveedorProductos,
                ['proveedor_id', 'producto_id'],
                ['proveedor_producto_id', 'codigo_proveedor', 'moneda', 
                 'stock', 'stock_cd', 'garantia', 'en_oferta', 'ultima_actualizacion', 'updated_at']
            );
        }

        return ProveedorProducto::where('proveedor_id', $proveedorIdBd)
            ->whereIn('producto_id', $productos->pluck('id'))
            ->get()
            ->keyBy(fn($pp) => $pp->proveedor_id . '-' . $pp->producto_id);
    }

    protected function insertarPreciosIniciales(array $productosDto, Collection $proveedorProductos): void
    {
        $datosPrecios = [];
        
        foreach ($productosDto as $dto) {
            $claveUnica = $this->obtenerClaveUnica($dto);
            $productos = Producto::where('upc', $claveUnica)->get();
            
            foreach ($productos as $producto) {
                $ppKey = $proveedorProductos->firstWhere('producto_id', $producto->id);
                
                if (!$ppKey) continue;
                if(!is_numeric($dto->precioActual)) continue;

                $datosPrecios[] = [
                    'proveedor_producto_id' => $ppKey->id,
                    'precio_actual' => $dto->precioActual,
                    'precio_anterior' => null,
                    'ultima_actualizacion' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        if (!empty($datosPrecios)) {
            DB::table('proveedor_producto_precios')->upsert(
                $datosPrecios,
                ['proveedor_producto_id'],
                ['precio_actual', 'ultima_actualizacion', 'updated_at']
            );
        }
    }

    protected function upsertImagenes(array $productosDto, Collection $productos): void
    {
        $datosImagenes = [];
        
        foreach ($productosDto as $dto) {
            $claveUnica = $this->obtenerClaveUnica($dto);
            $producto = $productos[$claveUnica] ?? null;
            
            if (!$producto) continue;

            foreach ($dto->imagenes as $rutaImagen) {
                $datosImagenes[] = [
                    'url_imagen' => $rutaImagen,
                    'producto_id' => $producto->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        if (!empty($datosImagenes)) {
            DB::table('producto_imagenes')->upsert(
                $datosImagenes,
                ['url_imagen', 'producto_id'],
                ['updated_at']
            );
        }
    }

    protected function actualizarImagenesProducto(ProductoData $dto, int $productoId): void
    {
        if (empty($dto->imagenes)) {
            return;
        }

        $datosImagenes = [];
        foreach ($dto->imagenes as $rutaImagen) {
            $datosImagenes[] = [
                'url_imagen' => $rutaImagen,
                'producto_id' => $productoId,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('producto_imagenes')->upsert(
            $datosImagenes,
            ['url_imagen', 'producto_id'],
            ['updated_at']
        );
    }

    protected function insertarPromocionesIniciales(array $productosDto, Collection $proveedorProductos): void
    {
        $datosPromociones = [];
        
        foreach ($productosDto as $dto) {
            if (!($dto->esOferta || $dto->descuentoTotal !== null)) {
                continue;
            }

            $claveUnica = $this->obtenerClaveUnica($dto);
            $productos = Producto::where('upc', $claveUnica)->get();
            
            foreach ($productos as $producto) {
                $ppKey = $proveedorProductos->firstWhere('producto_id', $producto->id);
                
                if (!$ppKey) continue;

                $datosPromociones[] = [
                    'proveedor_producto_id' => $ppKey->id,
                    'clave_promocion' => $dto->clavePromocion ?? 'PROMO-' . $ppKey->id,
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

        if (!empty($datosPromociones)) {
            DB::table('proveedor_producto_promociones')->upsert(
                $datosPromociones,
                ['proveedor_producto_id', 'clave_promocion'],
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

    protected function construirDatosPromocion(ProductoData $dto, int $proveedorProductoId): array
    {
        return [
            'proveedor_producto_id' => $proveedorProductoId,
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

    protected function promocionHaCambiado($ultimaPromocion, array $nuevaPromocion): bool
    {
        return $ultimaPromocion->clave_promocion != $nuevaPromocion['clave_promocion'];
    }

    protected function actualizarPromocionIndividual(ProductoData $dto, int $proveedorProductoId): void
    {
        $this->actualizarPromocionIndividualConBloqueo($dto, $proveedorProductoId);
    }

    // =========================================================================
    // MÉTODOS PROTEGIDOS - GESTIÓN DE DATOS MAESTROS
    // =========================================================================

    /**
     *Asegura que existan registros "General" en todas las tablas maestras
     */
    protected function asegurarRegistrosGeneralesExisten(): void
    {
        $timestamp = now();
        
        DB::table('categorias')->insertOrIgnore([
            'nombre' => 'General',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        
        DB::table('sub_categorias')->insertOrIgnore([
            'nombre' => 'General',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        
        DB::table('familias')->insertOrIgnore([
            'nombre' => 'General',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        
        DB::table('grupos')->insertOrIgnore([
            'nombre' => 'General',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        
        DB::table('marcas')->insertOrIgnore([
            'nombre' => 'General',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    protected function precargarRelacionesMaestras(): void
    {
        $this->cacheDeBusqueda['categorias'] = Cache::remember('categorias_map', self::CACHE_TTL, 
            fn() => Categoria::pluck('id', 'nombre')->toArray()
        );
        $this->cacheDeBusqueda['subcategorias'] = Cache::remember('subcategorias_map', self::CACHE_TTL,
            fn() => SubCategoria::pluck('id', 'nombre')->toArray()
        );
        $this->cacheDeBusqueda['familias'] = Cache::remember('familias_map', self::CACHE_TTL,
            fn() => Familia::pluck('id', 'nombre')->toArray()
        );
        $this->cacheDeBusqueda['grupos'] = Cache::remember('grupos_map', self::CACHE_TTL,
            fn() => Grupo::pluck('id', 'nombre')->toArray()
        );
        $this->cacheDeBusqueda['marcas'] = Cache::remember('marcas_map', self::CACHE_TTL,
            fn() => Marca::pluck('id', 'nombre')->toArray()
        );
    }

    protected function asegurarDatosMaestrosExisten(array $productosDto): void
    {
        $categorias = [];
        $subcategorias = [];
        $familias = [];
        $grupos = [];
        $marcas = [];

        foreach ($productosDto as $dto) {
            if ($dto->categoriaNombre && !isset($this->cacheDeBusqueda['categorias'][$dto->categoriaNombre])) {
                $categorias[$dto->categoriaNombre] = true;
            }
            
            $nombreSubCat = $dto->subcategoriaNombre ?? 'General';
            if (!isset($this->cacheDeBusqueda['subcategorias'][$nombreSubCat])) {
                $subcategorias[$nombreSubCat] = true;
            }
            
            $nombreFamilia = $dto->familiaNombre ?? 'General';
            if (!isset($this->cacheDeBusqueda['familias'][$nombreFamilia])) {
                $familias[$nombreFamilia] = true;
            }
            
            // ✅ Usar el nuevo método de procesamiento
            $nombreGrupo = $this->procesarNombreGrupo($dto->grupoNombre);
            if (!isset($this->cacheDeBusqueda['grupos'][$nombreGrupo])) {
                $grupos[$nombreGrupo] = true;
            }
            
            $nombreMarca = $dto->marcaNombre ?? 'General';
            if (!isset($this->cacheDeBusqueda['marcas'][$nombreMarca])) {
                $marcas[$nombreMarca] = true;
            }
        }

        $this->upsertDatosMaestros(Categoria::class, $categorias, 'categorias');
        $this->upsertDatosMaestros(SubCategoria::class, $subcategorias, 'subcategorias');
        $this->upsertDatosMaestros(Familia::class, $familias, 'familias');
        $this->upsertDatosMaestros(Grupo::class, $grupos, 'grupos');
        $this->upsertDatosMaestros(Marca::class, $marcas, 'marcas');
    }

    protected function upsertDatosMaestros(string $modelClass, array $nombres, string $claveDeCacheDeBusqueda): void
    {
        if (empty($nombres)) {
            return;
        }

        $datos = array_map(fn($nombre) => [
            'nombre' => $nombre,
            'created_at' => now(),
            'updated_at' => now()
        ], array_keys($nombres));

        $modelClass::upsert($datos, ['nombre'], ['updated_at']);
        
        $this->cacheDeBusqueda[$claveDeCacheDeBusqueda] = $modelClass::pluck('id', 'nombre')->toArray();
        Cache::put("{$claveDeCacheDeBusqueda}_map", $this->cacheDeBusqueda[$claveDeCacheDeBusqueda], self::CACHE_TTL);
    }

    // =========================================================================
    // MÉTODOS PROTEGIDOS - UTILIDADES
    // =========================================================================

    protected function construirRespuestaPaginacion(int $pagina, int $cantidad, int $totalPaginas): ?array
    {
        return [
            'actual' => $pagina,
            'total_paginas' => $totalPaginas,
            'productos_procesados' => $cantidad
        ];
    }
}