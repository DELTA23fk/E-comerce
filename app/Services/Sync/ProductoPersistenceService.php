<?php

namespace App\Services\Sync;

use App\Data\Producto\ProductoData;
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
use Illuminate\Support\Facades\Log;

/**
 * Servicio de Persistencia de Productos.
 *
 * Centraliza TODA la lógica de escritura en base de datos.
 * Es completamente agnóstico al proveedor: solo recibe ProductoData.
 *
 * Métodos públicos disponibles:
 *  - persistirBatch()              → Sync inicial (crea/actualiza todo)
 *  - actualizarPrecios()           → Solo precios
 *  - actualizarStock()             → Solo stock
 *  - actualizarPromociones()       → Solo promociones
 *  - persistirProductoIndividual() → Un único producto completo
 *
 * CORRECCIONES APLICADAS:
 *  - [PROMO]  Condición de entrada unificada entre batch e individual (esOferta || descuentoTotal !== null)
 *  - [PROMO]  promocionHaCambiado() maneja nulls correctamente y compara campos adicionales
 *  - [PROMO]  construirDatosPromocion() incluye updated_at
 *  - [PROMO]  Stats expiradas separadas: expiradas_reemplazadas vs expiradas_sin_oferta
 *  - [PRECIO] Comparación numérica explícita con (float) en vez de !=
 *  - [STOCK]  Comparación numérica explícita con (int) en vez de !=
 *  - [STOCK]  upsert masivo reemplazado por UPDATE directo para no sobreescribir campos ajenos
 *  - [STOCK]  buscarProveedorProducto sin lock reemplazado por versión con lock en batch
 *  - [BATCH]  N+1 queries eliminados en insertarPreciosIniciales e insertarPromocionesIniciales
 */
class ProductoPersistenceService
{
    // -------------------------------------------------------------------------
    // Configuración
    // -------------------------------------------------------------------------

    protected const BATCH_SIZE     = 500;
    protected const CACHE_TTL      = 3600;
    protected const MAX_RETRIES    = 3;
    protected const RETRY_DELAY_MS = 100;

    protected array $cache = [
        'categorias'    => [],
        'subcategorias' => [],
        'familias'      => [],
        'grupos'        => [],
        'marcas'        => [],
    ];

    public function __construct()
    {
        $this->asegurarRegistrosGeneralesExisten();
    }

    // =========================================================================
    // API PÚBLICA
    // =========================================================================

    /**
     * Sync inicial: inserta/actualiza productos, precios, stock, imágenes y promociones.
     *
     * @param  array<ProductoData>  $productosDto
     * @param  int                  $proveedorIdBd  ID del proveedor en tabla `proveedores`
     */
    public function persistirBatch(array $productosDto, int $proveedorIdBd): void
    {
        if (empty($productosDto)) {
            return;
        }

        $this->ejecutarConReintentos(function () use ($productosDto, $proveedorIdBd) {
            DB::transaction(function () use ($productosDto, $proveedorIdBd) {
                $this->precargarRelacionesMaestras();
                $this->asegurarDatosMaestrosExisten($productosDto);

                $dtosValidos        = $this->filtrarDtosValidos($productosDto);
                $productos          = $this->upsertProductos($dtosValidos);
                $proveedorProductos = $this->upsertProveedorProductos($dtosValidos, $productos, $proveedorIdBd);

                $this->insertarPreciosIniciales($dtosValidos, $productos, $proveedorProductos);
                $this->upsertImagenes($dtosValidos, $productos);
                $this->insertarPromocionesIniciales($dtosValidos, $productos, $proveedorProductos);
            }, attempts: 5);
        });
    }

    /**
     * Actualiza solo precios de una colección de productos normalizados.
     *
     * @param  Collection<ProductoData>  $productos
     */
    public function actualizarPrecios(Collection $productos, int $proveedorIdBd): array
    {
        $stats = ['total' => 0, 'actualizados' => 0, 'sin_cambios' => 0, 'errores' => 0];

        foreach ($productos->chunk(self::BATCH_SIZE) as $chunk) {
            $resultado = $this->ejecutarConReintentos(
                fn() => $this->actualizarPreciosBatch($chunk, $proveedorIdBd),
                self::MAX_RETRIES,
                fn($e) => $this->errorBatchStats($e, 'actualizarPrecios', $stats)
            );
            $this->sumarStats($stats, $resultado);
        }

        return $stats;
    }

    /**
     * Actualiza solo stock de una colección de productos normalizados.
     *
     * @param  Collection<ProductoData>  $productos
     */
    public function actualizarStock(Collection $productos, int $proveedorIdBd): array
    {
        $stats = ['total' => 0, 'actualizados' => 0, 'sin_cambios' => 0, 'errores' => 0];

        foreach ($productos->chunk(self::BATCH_SIZE) as $chunk) {
            $resultado = $this->ejecutarConReintentos(
                fn() => $this->actualizarStockBatch($chunk, $proveedorIdBd),
                self::MAX_RETRIES,
                fn($e) => $this->errorBatchStats($e, 'actualizarStock', $stats)
            );
            $this->sumarStats($stats, $resultado);
        }

        return $stats;
    }

    /**
     * Actualiza solo promociones de una colección de productos normalizados.
     *
     * @param  Collection<ProductoData>  $productos
     */
    public function actualizarPromociones(Collection $productos, int $proveedorIdBd): array
    {
        $stats = [
            'total'                => 0,
            'creadas'              => 0,
            'stock_actualizado'    => 0,
            'sin_cambios'          => 0,
            'expiradas_reemplazadas' => 0,  // FIX: separado de expiradas_sin_oferta
            'expiradas_sin_oferta' => 0,
            'errores'              => 0,
        ];

        foreach ($productos->chunk(self::BATCH_SIZE) as $chunk) {
            $resultado = $this->ejecutarConReintentos(
                fn() => $this->actualizarPromocionesBatch($chunk, $proveedorIdBd),
                self::MAX_RETRIES,
                fn($e) => $this->errorBatchStats($e, 'actualizarPromociones', $stats)
            );
            $this->sumarStats($stats, $resultado);
        }

        return $stats;
    }

    /**
     * Sincronización completa de un único ProductoData.
     */
    public function persistirProductoIndividual(ProductoData $dto, int $proveedorIdBd): array
    {
        return $this->ejecutarConReintentos(function () use ($dto, $proveedorIdBd) {
            DB::transaction(function () use ($dto, $proveedorIdBd) {
                $this->asegurarDatosMaestrosExisten([$dto]);
                $producto          = $this->buscarOCrearProductoConBloqueo($dto);
                $proveedorProducto = $this->buscarOCrearProveedorProductoConBloqueo($dto, $producto->id, $proveedorIdBd);
                $this->actualizarPrecioIndividualConBloqueo($dto, $proveedorProducto->id);
                $this->actualizarStockIndividualConBloqueo($dto, $proveedorProducto->id);
                $this->actualizarPromocionIndividualConBloqueo($dto, $proveedorProducto->id);
            }, attempts: 5);

            return ['exito' => true];
        }, self::MAX_RETRIES, fn($e) => ['exito' => false, 'error' => $e->getMessage()]);
    }

    // =========================================================================
    // PERSISTENCIA INTERNA - BATCH
    // =========================================================================

    protected function actualizarPreciosBatch(Collection $chunk, int $proveedorIdBd): array
    {
        $stats = ['total' => 0, 'actualizados' => 0, 'sin_cambios' => 0, 'errores' => 0];

        DB::transaction(function () use ($chunk, $proveedorIdBd, &$stats) {
            foreach ($chunk as $dto) {
                try {
                    $stats['total']++;

                    if (!$this->precioEsValido($dto->precioActual) || !$this->monedaEsValida($dto->moneda)) {
                        Log::warning('[Persistencia] Precio/moneda inválido en batch, omitido', [
                            'nombre' => $dto->nombre,
                            'precio' => $dto->precioActual,
                            'moneda' => $dto->moneda,
                        ]);
                        $stats['errores']++;
                        continue;
                    }

                    $pp = $this->buscarProveedorProductoConBloqueo($dto, $proveedorIdBd);
                    if (!$pp) { $stats['errores']++; continue; }

                    $registro = DB::table('proveedor_producto_precios')
                        ->where('proveedor_producto_id', $pp->id)
                        ->lockForUpdate()
                        ->first();

                    // FIX: comparación numérica explícita para evitar falsas igualdades por tipo
                    $precioActualBd  = $registro ? (float) $registro->precio_actual : null;
                    $precioNuevo     = (float) $dto->precioActual;
                    $precioHaCambiado = $precioActualBd === null || $precioActualBd !== $precioNuevo;

                    if (!$registro || $precioHaCambiado) {
                        $registro
                            ? DB::table('proveedor_producto_precios')
                                ->where('proveedor_producto_id', $pp->id)
                                ->update([
                                    'precio_anterior'      => $registro->precio_actual,
                                    'precio_actual'        => $dto->precioActual,
                                    'ultima_actualizacion' => now(),
                                    'updated_at'           => now(),
                                ])
                            : DB::table('proveedor_producto_precios')->insert([
                                'proveedor_producto_id' => $pp->id,
                                'precio_actual'         => $dto->precioActual,
                                'precio_anterior'       => null,
                                'ultima_actualizacion'  => now(),
                                'created_at'            => now(),
                                'updated_at'            => now(),
                            ]);

                        $stats['actualizados']++;
                    } else {
                        $stats['sin_cambios']++;
                    }
                } catch (\Throwable $e) {
                    Log::error('[Persistencia] Error actualizando precio', ['error' => $e->getMessage()]);
                    $stats['errores']++;
                }
            }
        }, attempts: 5);

        return $stats;
    }

    protected function actualizarStockBatch(Collection $chunk, int $proveedorIdBd): array
    {
        $stats = ['total' => 0, 'actualizados' => 0, 'sin_cambios' => 0, 'errores' => 0];

        DB::transaction(function () use ($chunk, $proveedorIdBd, &$stats) {
            $ids          = [];
            $stockData    = []; // FIX: solo guardamos los valores nuevos de stock, no la fila completa

            foreach ($chunk as $dto) {
                try {
                    $stats['total']++;

                    // FIX: usar lock desde la lectura para que la decisión de cambio sea consistente
                    $pp = $this->buscarProveedorProductoConBloqueo($dto, $proveedorIdBd);

                    if (!$pp) { $stats['errores']++; continue; }

                    // FIX: comparación numérica explícita
                    $stockHaCambiado = (int) $pp->stock !== (int) $dto->stock
                        || (int) $pp->stock_cd !== (int) $dto->stockCD;

                    if ($stockHaCambiado) {
                        $ids[]             = $pp->id;
                        $stockData[$pp->id] = [
                            'stock'    => $dto->stock,
                            'stock_cd' => $dto->stockCD,
                        ];
                        $stats['actualizados']++;
                    } else {
                        $stats['sin_cambios']++;
                    }
                } catch (\Throwable $e) {
                    Log::error('[Persistencia] Error procesando stock', ['error' => $e->getMessage()]);
                    $stats['errores']++;
                }
            }

            if (!empty($ids)) {
                // FIX: UPDATE directo con CASE/WHEN en vez de upsert completo,
                // para no sobreescribir moneda, garantia, codigo_proveedor, etc.
                $this->actualizarStockMasivo($ids, $stockData);
            }
        }, attempts: 5);

        return $stats;
    }

    /**
     * Ejecuta un UPDATE masivo de stock/stock_cd usando CASE WHEN,
     * garantizando que solo se tocan esos dos campos.
     *
     * @param  int[]   $ids
     * @param  array<int, array{stock: mixed, stock_cd: mixed}>  $stockData
     */
    protected function actualizarStockMasivo(array $ids, array $stockData): void
    {
        sort($ids); // ordenar para consistent locking y evitar deadlocks

        $casesStock   = '';
        $casesStockCd = '';
        $bindings     = [];

        foreach ($ids as $id) {
            $casesStock   .= " WHEN ? THEN ?";
            $casesStockCd .= " WHEN ? THEN ?";
            $bindings[]    = $id;
            $bindings[]    = $stockData[$id]['stock'];
        }

        // Bindings para el CASE de stock_cd
        $bindingsStockCd = [];
        foreach ($ids as $id) {
            $bindingsStockCd[] = $id;
            $bindingsStockCd[] = $stockData[$id]['stock_cd'];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        DB::statement(
            "UPDATE proveedor_productos
             SET stock                = CASE id {$casesStock} END,
                 stock_cd             = CASE id {$casesStockCd} END,
                 ultima_actualizacion = NOW(),
                 updated_at           = NOW()
             WHERE id IN ({$placeholders})",
            array_merge($bindings, $bindingsStockCd, $ids)
        );
    }

    protected function actualizarPromocionesBatch(Collection $chunk, int $proveedorIdBd): array
    {
        $stats = [
            'total'                  => 0,
            'creadas'                => 0,
            'stock_actualizado'      => 0,
            'sin_cambios'            => 0,
            'expiradas_reemplazadas' => 0, // FIX: antes "expiradas" mezclaba dos conceptos
            'expiradas_sin_oferta'   => 0,
            'errores'                => 0,
        ];

        DB::transaction(function () use ($chunk, $proveedorIdBd, &$stats) {
            foreach ($chunk as $dto) {
                try {
                    $stats['total']++;
                    $pp = $this->buscarProveedorProductoConBloqueo($dto, $proveedorIdBd);

                    if (!$pp) { $stats['errores']++; continue; }

                    $ultima = DB::table('proveedor_producto_promociones')
                        ->where('proveedor_producto_id', $pp->id)
                        ->orderBy('id', 'desc')
                        ->lockForUpdate()
                        ->first();

                    // FIX: condición unificada con el método individual
                    $tienePromocion = $dto->esOferta || $dto->descuentoTotal !== null;

                    if ($tienePromocion) {
                        $nueva = $this->construirDatosPromocion($dto, $pp->id);

                        if (!$ultima || $this->promocionHaCambiado($ultima, $nueva)) {
                            if ($ultima) {
                                DB::table('proveedor_producto_promociones')
                                    ->where('id', $ultima->id)
                                    ->update(['es_oferta' => false, 'updated_at' => now()]);
                                $stats['expiradas_reemplazadas']++; // FIX: stat diferenciado
                            }
                            DB::table('proveedor_producto_promociones')->insert($nueva);
                            DB::table('proveedor_productos')->where('id', $pp->id)->update(['en_oferta' => true]);
                            $stats['creadas']++;
                        } else {
                            // Misma promoción → solo actualizar stock y precios derivados
                            DB::table('proveedor_producto_promociones')
                                ->where('id', $ultima->id)
                                ->update([
                                    'total_descuento'         => $nueva['total_descuento'],
                                    'precio_con_descuento'    => $nueva['precio_con_descuento'],
                                    'precio_oferta'           => $nueva['precio_oferta'],
                                    'precio_regular'          => $nueva['precio_regular'],
                                    'disponible_en_promocion' => $nueva['disponible_en_promocion'],
                                    'expiracion'              => $nueva['expiracion'],
                                    'updated_at'              => now(),
                                ]);
                            $stats['stock_actualizado']++;
                        }
                    } elseif ($pp->en_oferta) {
                        if ($ultima) {
                            DB::table('proveedor_producto_promociones')
                                ->where('id', $ultima->id)
                                ->update(['es_oferta' => false, 'updated_at' => now()]);
                        }
                        DB::table('proveedor_productos')->where('id', $pp->id)->update(['en_oferta' => false]);
                        $stats['expiradas_sin_oferta']++; // FIX: stat diferenciado
                    } else {
                        $stats['sin_cambios']++;
                    }
                } catch (\Throwable $e) {
                    Log::error('[Persistencia] Error procesando promoción', ['error' => $e->getMessage()]);
                    $stats['errores']++;
                }
            }
        }, attempts: 5);

        return $stats;
    }

    // =========================================================================
    // PERSISTENCIA INTERNA - INDIVIDUAL CON BLOQUEO
    // =========================================================================

    /**
     * Busca el producto base por UPC/código de barras.
     * - Si NO existe → lo crea completo.
     * - Si YA existe → solo rellena campos que estén NULL/vacíos.
     *   Nunca sobreescribe datos que otro proveedor ya insertó.
     */
    protected function buscarOCrearProductoConBloqueo(ProductoData $dto): Producto
    {
        $clave   = $this->claveUnica($dto);
        $producto = Producto::where('upc', $clave)->lockForUpdate()->first();

        $grupoNombre = $this->procesarNombreGrupo($dto->grupoNombre);

        $datos = [
            'upc'                 => $clave,
            'nombre'              => $dto->nombre,
            'descripcion'         => $dto->descripcion,
            'descripcion_tecnica' => $dto->descripcionTecnica,
            'categoria_id'        => $this->cache['categorias'][$dto->categoriaNombre] ?? null,
            'sub_categoria_id'    => $this->cache['subcategorias'][$dto->subcategoriaNombre ?? 'General'] ?? null,
            'familia_id'          => $this->cache['familias'][$dto->familiaNombre ?? 'General'] ?? null,
            'grupo_id'            => $this->cache['grupos'][$grupoNombre] ?? null,
            'marca_id'            => $this->cache['marcas'][$dto->marcaNombre ?? 'General'] ?? null,
            'codigo_fabricante'   => $dto->codigoFabricante,
            'codigo_barras'       => $dto->codigoBarras,
        ];

        if (!$producto) {
            return Producto::create($datos);
        }

        $camposRellenables = [
            'nombre', 'descripcion', 'descripcion_tecnica',
            'categoria_id', 'sub_categoria_id', 'familia_id',
            'grupo_id', 'marca_id', 'codigo_fabricante', 'codigo_barras',
        ];

        $actualizaciones = [];
        foreach ($camposRellenables as $campo) {
            if (empty($producto->{$campo}) && !empty($datos[$campo])) {
                $actualizaciones[$campo] = $datos[$campo];
            }
        }

        if (!empty($actualizaciones)) {
            $actualizaciones['updated_at'] = now();
            $producto->update($actualizaciones);
        }

        return $producto->fresh();
    }

    protected function buscarOCrearProveedorProductoConBloqueo(ProductoData $dto, int $productoId, int $proveedorIdBd): ProveedorProducto
    {
        $pp = ProveedorProducto::where('proveedor_id', $proveedorIdBd)
            ->where('producto_id', $productoId)
            ->lockForUpdate()
            ->first();

        return $pp ?? ProveedorProducto::create([
            'proveedor_id'          => $proveedorIdBd,
            'producto_id'           => $productoId,
            'proveedor_producto_id' => $dto->proveedorProductoId,
            'codigo_proveedor'      => $dto->proveedorProductoCodigo,
            'moneda'                => $dto->moneda,
            'stock'                 => $dto->stock,
            'stock_cd'              => $dto->stockCD,
            'garantia'              => $dto->garantia,
            'en_oferta'             => $dto->enOferta,
            'ultima_actualizacion'  => now(),
        ]);
    }

    protected function actualizarPrecioIndividualConBloqueo(ProductoData $dto, int $ppId): void
    {
        if (!$this->precioEsValido($dto->precioActual) || !$this->monedaEsValida($dto->moneda)) {
            Log::warning('[Persistencia] Precio/moneda inválido en sync individual, omitido', [
                'nombre' => $dto->nombre,
                'precio' => $dto->precioActual,
                'moneda' => $dto->moneda,
            ]);
            return;
        }

        $registro = DB::table('proveedor_producto_precios')
            ->where('proveedor_producto_id', $ppId)
            ->lockForUpdate()
            ->first();

        // FIX: comparación numérica explícita
        $precioNuevo     = (float) $dto->precioActual;
        $precioActualBd  = $registro ? (float) $registro->precio_actual : null;
        $precioHaCambiado = $precioActualBd === null || $precioActualBd !== $precioNuevo;

        if (!$registro) {
            DB::table('proveedor_producto_precios')->insert([
                'proveedor_producto_id' => $ppId,
                'precio_actual'         => $dto->precioActual,
                'precio_anterior'       => null,
                'ultima_actualizacion'  => now(),
                'created_at'            => now(),
                'updated_at'            => now(),
            ]);
        } elseif ($precioHaCambiado) {
            DB::table('proveedor_producto_precios')
                ->where('proveedor_producto_id', $ppId)
                ->update([
                    'precio_anterior'      => $registro->precio_actual,
                    'precio_actual'        => $dto->precioActual,
                    'ultima_actualizacion' => now(),
                    'updated_at'           => now(),
                ]);
        }
    }

    protected function actualizarStockIndividualConBloqueo(ProductoData $dto, int $ppId): void
    {
        $pp = ProveedorProducto::where('id', $ppId)->lockForUpdate()->first();

        // FIX: comparación numérica explícita
        if ($pp && ((int) $pp->stock !== (int) $dto->stock || (int) $pp->stock_cd !== (int) $dto->stockCD)) {
            $pp->update([
                'stock'                => $dto->stock,
                'stock_cd'             => $dto->stockCD,
                'ultima_actualizacion' => now(),
            ]);
        }
    }

    protected function actualizarPromocionIndividualConBloqueo(ProductoData $dto, int $ppId): void
    {
        $ultima = DB::table('proveedor_producto_promociones')
            ->where('proveedor_producto_id', $ppId)
            ->orderBy('created_at', 'desc')
            ->lockForUpdate()
            ->first();

        // FIX: condición unificada con el método batch
        $tienePromocion = $dto->esOferta || $dto->descuentoTotal !== null;

        if ($tienePromocion) {
            $nueva = $this->construirDatosPromocion($dto, $ppId);

            if (!$ultima || $this->promocionHaCambiado($ultima, $nueva)) {
                if ($ultima) {
                    DB::table('proveedor_producto_promociones')
                        ->where('id', $ultima->id)
                        ->update(['es_oferta' => false, 'updated_at' => now()]);
                }
                DB::table('proveedor_producto_promociones')->insert($nueva);
                ProveedorProducto::where('id', $ppId)->update(['en_oferta' => true]);
            } else {
                // Misma promoción → actualizar solo stock y precios derivados
                DB::table('proveedor_producto_promociones')
                    ->where('id', $ultima->id)
                    ->update([
                        'total_descuento'         => $nueva['total_descuento'],
                        'precio_con_descuento'    => $nueva['precio_con_descuento'],
                        'precio_oferta'           => $nueva['precio_oferta'],
                        'precio_regular'          => $nueva['precio_regular'],
                        'disponible_en_promocion' => $nueva['disponible_en_promocion'],
                        'expiracion'              => $nueva['expiracion'],
                        'updated_at'              => now(),
                    ]);
            }
        } elseif ($ultima) {
            DB::table('proveedor_producto_promociones')
                ->where('id', $ultima->id)
                ->update(['es_oferta' => false, 'updated_at' => now()]);

            ProveedorProducto::where('id', $ppId)->update(['en_oferta' => false]);
        }
    }

    // =========================================================================
    // PERSISTENCIA INTERNA - UPSERT MASIVO
    // =========================================================================

    /**
     * Persiste los productos base con estrategia "no sobreescribir":
     * - Si el producto NO existe → insertOrIgnore (INSERT IGNORE en MySQL)
     * - Si el producto YA existe → solo rellena campos que estén NULL/vacíos
     */
    protected function upsertProductos(array $dtosValidos): Collection
    {
        if (empty($dtosValidos)) {
            return collect();
        }

        $filas = [];

        foreach ($dtosValidos as $dto) {
            $grupoNombre = $this->procesarNombreGrupo($dto->grupoNombre);

            $filas[] = [
                'upc'                 => $this->claveUnica($dto),
                'nombre'              => $dto->nombre,
                'descripcion'         => $dto->descripcion,
                'descripcion_tecnica' => $dto->descripcionTecnica,
                'categoria_id'        => $this->cache['categorias'][$dto->categoriaNombre] ?? null,
                'sub_categoria_id'    => $this->cache['subcategorias'][$dto->subcategoriaNombre ?? 'General'] ?? null,
                'familia_id'          => $this->cache['familias'][$dto->familiaNombre ?? 'General'] ?? null,
                'grupo_id'            => $this->cache['grupos'][$grupoNombre] ?? null,
                'marca_id'            => $this->cache['marcas'][$dto->marcaNombre ?? 'General'] ?? null,
                'codigo_fabricante'   => $dto->codigoFabricante,
                'codigo_barras'       => $dto->codigoBarras,
                'updated_at'          => now(),
                'created_at'          => now(),
            ];
        }

        Producto::insertOrIgnore($filas);

        $upcs              = array_column($filas, 'upc');
        $productosActuales = Producto::whereIn('upc', $upcs)->get()->keyBy('upc');

        $camposRellenables = [
            'nombre', 'descripcion', 'descripcion_tecnica',
            'categoria_id', 'sub_categoria_id', 'familia_id',
            'grupo_id', 'marca_id', 'codigo_fabricante', 'codigo_barras',
        ];

        foreach ($filas as $fila) {
            $producto = $productosActuales[$fila['upc']] ?? null;
            if (!$producto) continue;

            $actualizaciones = [];
            foreach ($camposRellenables as $campo) {
                if (empty($producto->{$campo}) && !empty($fila[$campo])) {
                    $actualizaciones[$campo] = $fila[$campo];
                }
            }

            if (!empty($actualizaciones)) {
                $actualizaciones['updated_at'] = now();
                Producto::where('upc', $fila['upc'])->update($actualizaciones);
                $productosActuales[$fila['upc']]->fill($actualizaciones);
            }
        }

        return $productosActuales;
    }

    protected function upsertProveedorProductos(array $dtosValidos, Collection $productos, int $proveedorIdBd): Collection
    {
        $filas = [];

        foreach ($dtosValidos as $dto) {
            $producto = $productos[$this->claveUnica($dto)] ?? null;
            if (!$producto) continue;

            $filas[] = [
                'proveedor_id'          => $proveedorIdBd,
                'producto_id'           => $producto->id,
                'proveedor_producto_id' => $dto->proveedorProductoId,
                'codigo_proveedor'      => $dto->proveedorProductoCodigo,
                'moneda'                => $dto->moneda,
                'stock'                 => $dto->stock,
                'stock_cd'              => $dto->stockCD,
                'garantia'              => $dto->garantia,
                'en_oferta'             => $dto->enOferta,
                'ultima_actualizacion'  => now(),
                'updated_at'            => now(),
                'created_at'            => now(),
            ];
        }

        if (!empty($filas)) {
            DB::table('proveedor_productos')->upsert($filas, ['proveedor_id', 'producto_id'], [
                'proveedor_producto_id', 'codigo_proveedor', 'moneda',
                'stock', 'stock_cd', 'garantia', 'en_oferta', 'ultima_actualizacion', 'updated_at',
            ]);
        }

        return ProveedorProducto::where('proveedor_id', $proveedorIdBd)
            ->whereIn('producto_id', $productos->pluck('id'))
            ->get()
            ->keyBy(fn($pp) => $pp->proveedor_id . '-' . $pp->producto_id);
    }

    /**
     * FIX: recibe $productos para evitar N+1 queries (antes hacía Producto::where por cada dto)
     */
    protected function insertarPreciosIniciales(array $dtosValidos, Collection $productos, Collection $proveedorProductos): void
    {
        $filas = [];

        foreach ($dtosValidos as $dto) {
            if (!is_numeric($dto->precioActual)) continue;

            // FIX: usar la colección ya cargada en vez de query individual
            $producto = $productos[$this->claveUnica($dto)] ?? null;
            if (!$producto) continue;

            $pp = $proveedorProductos->firstWhere('producto_id', $producto->id);
            if (!$pp) continue;

            $filas[] = [
                'proveedor_producto_id' => $pp->id,
                'precio_actual'         => $dto->precioActual,
                'precio_anterior'       => null,
                'ultima_actualizacion'  => now(),
                'created_at'            => now(),
                'updated_at'            => now(),
            ];
        }

        if (!empty($filas)) {
            DB::table('proveedor_producto_precios')->upsert(
                $filas, ['proveedor_producto_id'], ['precio_actual', 'ultima_actualizacion', 'updated_at']
            );
        }
    }

    protected function upsertImagenes(array $dtosValidos, Collection $productos): void
    {
        $filas = [];

        foreach ($dtosValidos as $dto) {
            $producto = $productos[$this->claveUnica($dto)] ?? null;
            if (!$producto) continue;

            foreach ($dto->imagenes as $url) {
                $filas[] = [
                    'url_imagen'  => $url,
                    'producto_id' => $producto->id,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ];
            }
        }

        if (!empty($filas)) {
            DB::table('producto_imagenes')->upsert($filas, ['url_imagen', 'producto_id'], ['updated_at']);
        }
    }

    /**
     * FIX: recibe $productos para evitar N+1 queries (antes hacía Producto::where por cada dto)
     */
    protected function insertarPromocionesIniciales(array $dtosValidos, Collection $productos, Collection $proveedorProductos): void
    {
        $filas = [];

        foreach ($dtosValidos as $dto) {
            // FIX: condición unificada con batch e individual
            $tienePromocion = $dto->esOferta || $dto->descuentoTotal !== null;
            if (!$tienePromocion) continue;

            // FIX: usar la colección ya cargada en vez de query individual
            $producto = $productos[$this->claveUnica($dto)] ?? null;
            if (!$producto) continue;

            $pp = $proveedorProductos->firstWhere('producto_id', $producto->id);
            if (!$pp) continue;

            $filas[] = array_merge(
                $this->construirDatosPromocion($dto, $pp->id),
                ['clave_promocion' => $dto->clavePromocion ?? 'PROMO-' . $pp->id]
            );
        }

        if (!empty($filas)) {
            DB::table('proveedor_producto_promociones')->upsert(
                $filas,
                ['proveedor_producto_id', 'clave_promocion'],
                [
                    'total_descuento', 'moneda_descuento', 'precio_con_descuento',
                    'descuento_precio_moneda', 'descripcion_promocion', 'expiracion',
                    'disponible_en_promocion', 'precio_oferta', 'precio_regular',
                    'es_oferta', 'updated_at',
                ]
            );
        }
    }

    // =========================================================================
    // DATOS MAESTROS
    // =========================================================================

    protected function asegurarRegistrosGeneralesExisten(): void
    {
        $ts     = now();
        $tablas = ['categorias', 'sub_categorias', 'familias', 'grupos', 'marcas'];

        foreach ($tablas as $tabla) {
            DB::table($tabla)->insertOrIgnore(['nombre' => 'General', 'created_at' => $ts, 'updated_at' => $ts]);
        }
    }

    protected function precargarRelacionesMaestras(): void
    {
        $map = [
            'categorias'    => Categoria::class,
            'subcategorias' => SubCategoria::class,
            'familias'      => Familia::class,
            'grupos'        => Grupo::class,
            'marcas'        => Marca::class,
        ];

        foreach ($map as $clave => $model) {
            $this->cache[$clave] = Cache::remember(
                "{$clave}_map",
                self::CACHE_TTL,
                fn() => $model::pluck('id', 'nombre')->toArray()
            );
        }
    }

    protected function asegurarDatosMaestrosExisten(array $productosDto): void
    {
        $categorias = $subcategorias = $familias = $grupos = $marcas = [];

        foreach ($productosDto as $dto) {
            if ($dto->categoriaNombre && !isset($this->cache['categorias'][$dto->categoriaNombre]))
                $categorias[$dto->categoriaNombre] = true;

            $sub = $dto->subcategoriaNombre ?? 'General';
            if (!isset($this->cache['subcategorias'][$sub])) $subcategorias[$sub] = true;

            $fam = $dto->familiaNombre ?? 'General';
            if (!isset($this->cache['familias'][$fam])) $familias[$fam] = true;

            $grp = $this->procesarNombreGrupo($dto->grupoNombre);
            if (!isset($this->cache['grupos'][$grp])) $grupos[$grp] = true;

            $marca = $dto->marcaNombre ?? 'General';
            if (!isset($this->cache['marcas'][$marca])) $marcas[$marca] = true;
        }

        $this->upsertMaestros(Categoria::class,   $categorias,    'categorias');
        $this->upsertMaestros(SubCategoria::class, $subcategorias, 'subcategorias');
        $this->upsertMaestros(Familia::class,      $familias,      'familias');
        $this->upsertMaestros(Grupo::class,        $grupos,        'grupos');
        $this->upsertMaestros(Marca::class,        $marcas,        'marcas');
    }

    protected function upsertMaestros(string $model, array $nombres, string $cacheKey): void
    {
        if (empty($nombres)) return;

        $datos = array_map(
            fn($nombre) => ['nombre' => $nombre, 'created_at' => now(), 'updated_at' => now()],
            array_keys($nombres)
        );

        $model::upsert($datos, ['nombre'], ['updated_at']);
        $this->cache[$cacheKey] = $model::pluck('id', 'nombre')->toArray();
        Cache::put("{$cacheKey}_map", $this->cache[$cacheKey], self::CACHE_TTL);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    protected function claveUnica(ProductoData $dto): ?string
    {
        return $dto->upc ?? $dto->codigoBarras ?? null;
    }

    protected function filtrarDtosValidos(array $productosDto): array
    {
        return array_filter($productosDto, function ($dto) {
            $clave = $this->claveUnica($dto);

            if (!$clave) {
                Log::warning('[Persistencia] DTO sin clave única, omitido', ['nombre' => $dto->nombre]);
                return false;
            }

            if (!$this->precioEsValido($dto->precioActual)) {
                Log::warning('[Persistencia] DTO con precio inválido, omitido', [
                    'nombre' => $dto->nombre,
                    'precio' => $dto->precioActual,
                ]);
                return false;
            }

            if (!$this->monedaEsValida($dto->moneda)) {
                Log::warning('[Persistencia] DTO con moneda inválida, omitido', [
                    'nombre' => $dto->nombre,
                    'moneda' => $dto->moneda,
                ]);
                return false;
            }

            return true;
        });
    }

    /**
     * Valida que el precio sea un número positivo.
     * Descarta: null, string vacío, strings no numéricos, cero o negativos.
     */
    protected function precioEsValido(mixed $precio): bool
    {
        if ($precio === null || $precio === '') {
            return false;
        }

        $limpio = preg_replace('/[^0-9.]/', '', (string) $precio);

        if (!is_numeric($limpio) || (float) $limpio <= 0) {
            return false;
        }

        if ((float) $limpio > 9_999_999) {
            Log::warning('[Persistencia] Precio sospechosamente alto descartado', ['precio' => $precio]);
            return false;
        }

        return true;
    }

    /**
     * Valida que la moneda sea un código ISO 4217 conocido.
     * Descarta: null, vacío, strings que no sean códigos válidos.
     */
    protected function monedaEsValida(mixed $moneda): bool
    {
        if ($moneda === null || trim((string) $moneda) === '') {
            return false;
        }

        $monedasAceptadas = ['MXN', 'USD', 'EUR'];

        return in_array(strtoupper(trim((string) $moneda)), $monedasAceptadas, true);
    }

    protected function buscarProveedorProductoConBloqueo(ProductoData $dto, int $proveedorIdBd)
    {
        return DB::table('proveedor_productos as pp')
            ->join('productos as p', 'pp.producto_id', '=', 'p.id')
            ->where('pp.proveedor_id', $proveedorIdBd)
            ->where('p.upc', $this->claveUnica($dto))
            ->select('pp.*')
            ->lockForUpdate()
            ->first();
    }

    // FIX: conservado para compatibilidad pero ya no se usa en actualizarStockBatch
    protected function buscarProveedorProducto(ProductoData $dto, int $proveedorIdBd)
    {
        return DB::table('proveedor_productos as pp')
            ->join('productos as p', 'pp.producto_id', '=', 'p.id')
            ->where('pp.proveedor_id', $proveedorIdBd)
            ->where('p.upc', $this->claveUnica($dto))
            ->select('pp.*')
            ->first();
    }

    /**
     * FIX: incluye updated_at (antes faltaba y causaba null en strict mode).
     * FIX: los campos que actualiza el bloque "misma promoción" ahora están completos
     *      (precio_oferta, precio_regular, expiracion) — antes quedaban stale.
     */
    protected function construirDatosPromocion(ProductoData $dto, int $ppId): array
    {
        return [
            'proveedor_producto_id'   => $ppId,
            'total_descuento'         => $dto->descuentoTotal,
            'moneda_descuento'        => $dto->descuentoMoneda,
            'precio_con_descuento'    => $dto->descuentoPrecio,
            'descuento_precio_moneda' => $dto->descuentoPrecioMoneda,
            'clave_promocion'         => $dto->clavePromocion,
            'descripcion_promocion'   => $dto->promocionDescripcion,
            'expiracion'              => $dto->promocionExpiracion,
            'disponible_en_promocion' => $dto->disponiblesEnPromocion,
            'precio_oferta'           => $dto->ofertaPrecio,
            'precio_regular'          => $dto->precioRegular,
            'es_oferta'               => $dto->esOferta,
            'created_at'              => now(),
            'updated_at'              => now(), // FIX: faltaba
        ];
    }

    /**
     * FIX: manejo correcto de nulls.
     * - Si alguna clave es null → siempre se considera cambio (antes null != null era false).
     * FIX: compara también descripcion y expiracion para detectar cambios reales de promoción
     *      más allá de solo la clave.
     */
    protected function promocionHaCambiado($ultima, array $nueva): bool
    {
        // Si alguna clave es null, no podemos confiar en la comparación → asumir cambio
        if ($ultima->clave_promocion === null || $nueva['clave_promocion'] === null) {
            return true;
        }

        if ($ultima->clave_promocion !== $nueva['clave_promocion']) {
            return true;
        }

        // Campos adicionales que, si cambian, ameritan una nueva fila de historial
        if ($ultima->descripcion_promocion !== $nueva['descripcion_promocion']) {
            return true;
        }

        if ($ultima->expiracion !== $nueva['expiracion']) {
            return true;
        }

        return false;
    }

    protected function procesarNombreGrupo(?string $grupoNombre): string
    {
        if (empty($grupoNombre)) return 'General';

        $partes = explode('/', str_replace('\/', '/', $grupoNombre));
        $nombre = trim($partes[0]);

        return !empty($nombre) ? substr($nombre, 0, 255) : 'General';
    }

    // =========================================================================
    // REINTENTOS Y DEADLOCKS
    // =========================================================================

    protected function ejecutarConReintentos(callable $op, int $maxIntentos = self::MAX_RETRIES, ?callable $onError = null)
    {
        $intento = 0;

        while ($intento < $maxIntentos) {
            try {
                return $op();
            } catch (\Throwable $e) {
                $intento++;

                if ($this->esDeadlock($e) && $intento < $maxIntentos) {
                    usleep(self::RETRY_DELAY_MS * pow(2, $intento - 1) * 1000);
                    Log::warning('[Persistencia] Deadlock, reintentando', ['intento' => $intento]);
                    continue;
                }

                if ($onError) return $onError($e);
                throw $e;
            }
        }
    }

    protected function esDeadlock(\Throwable $e): bool
    {
        return str_contains($e->getMessage(), 'Deadlock') ||
               str_contains($e->getMessage(), 'try restarting transaction') ||
               $e->getCode() === '40001' ||
               $e->getCode() === 1213;
    }

    protected function sumarStats(array &$base, ?array $nuevo): void
    {
        if (!$nuevo) return;
        foreach ($nuevo as $key => $val) {
            if (isset($base[$key]) && is_numeric($val)) {
                $base[$key] += $val;
            }
        }
    }

    protected function errorBatchStats(\Throwable $e, string $contexto, array $statsBase): array
    {
        Log::error("[Persistencia] Error en {$contexto}", ['error' => $e->getMessage()]);
        return array_merge(array_map(fn() => 0, $statsBase), ['errores' => 1]);
    }
}