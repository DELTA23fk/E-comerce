<?php

namespace App\Services\Sync;

use App\Data\Producto\AlmacenStockData;
use App\Data\Producto\ProductoData;
use App\Data\Producto\PromocionData;
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

    public function persistirBatch(array $productosDto, int $proveedorIdBd): void
    {
        if (empty($productosDto)) return;

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

                // Almacenes: resolver maestros + upsert stock en una sola pasada
                $this->upsertAlmacenesBatch($dtosValidos, $productos, $proveedorProductos, $proveedorIdBd);

            }, attempts: 5);
        });
    }

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

    public function actualizarPromociones(Collection $productos, int $proveedorIdBd): array
    {
        $stats = [
            'total'                  => 0,
            'creadas'                => 0,
            'stock_actualizado'      => 0,
            'sin_cambios'            => 0,
            'expiradas_reemplazadas' => 0,
            'expiradas_sin_oferta'   => 0,
            'errores'                => 0,
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
     * Actualización paginada de stock por almacén.
     * Llamado desde el orquestador igual que actualizarStock().
     *
     * @return array{total: int, actualizados: int, sin_cambios: int, errores: int}
     */
    public function actualizarAlmacenes(Collection $productos, int $proveedorIdBd): array
    {
        $stats = ['total' => 0, 'actualizados' => 0, 'sin_cambios' => 0, 'errores' => 0];

        foreach ($productos->chunk(self::BATCH_SIZE) as $chunk) {
            $resultado = $this->ejecutarConReintentos(
                fn() => $this->actualizarAlmacenesBatch($chunk, $proveedorIdBd),
                self::MAX_RETRIES,
                fn($e) => $this->errorBatchStats($e, 'actualizarAlmacenes', $stats)
            );
            $this->sumarStats($stats, $resultado);
        }

        return $stats;
    }

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
                $this->actualizarAlmacenesIndividual($dto, $proveedorProducto->id, $proveedorIdBd);

            }, attempts: 5);

            return ['exito' => true];
        }, self::MAX_RETRIES, fn($e) => ['exito' => false, 'error' => $e->getMessage()]);
    }

    // =========================================================================
    // ALMACENES — MAESTROS
    // =========================================================================

    /**
     * Asegura que los almacenes maestros del proveedor existen en BD.
     * Devuelve mapa: nombre → almacen_id
     *
     * Estrategia:
     *   1. Deduplicar por nombre (un batch puede traer el mismo almacén N veces)
     *   2. insertOrIgnore — no sobreescribe metadatos ya guardados
     *   3. SELECT para obtener IDs de todos (nuevos + existentes)
     *
     * Los metadatos (CP, es_cd, es_principal) solo se escriben en la
     * inserción inicial; el sync nunca los sobreescribe.
     */
    protected function resolverAlmacenesMaestros(array $almacenesData, int $proveedorId): array
    {
        if (empty($almacenesData)) return [];

        // Deduplicar por nombre para evitar intentar insertar duplicados en el mismo batch
        $filasPorNombre = [];
        foreach ($almacenesData as $a) {
            if (isset($filasPorNombre[$a->almacenNombre])) continue;

            $filasPorNombre[$a->almacenNombre] = [
                'proveedor_id'       => $proveedorId,
                'almacen_id_externo' => $a->almacenIdExterno,
                'nombre'             => $a->almacenNombre,
                'codigo_postal'      => $a->codigoPostal,
                'es_principal'       => $a->esPrincipal  ? 1 : 0,
                'es_cd'              => $a->esCd         ? 1 : 0,
                'created_at'         => now(),
                'updated_at'         => now(),
            ];
        }

        DB::table('proveedor_almacenes')->insertOrIgnore(array_values($filasPorNombre));

        return DB::table('proveedor_almacenes')
            ->where('proveedor_id', $proveedorId)
            ->whereIn('nombre', array_keys($filasPorNombre))
            ->pluck('id', 'nombre')
            ->toArray();
    }

    // =========================================================================
    // ALMACENES — UPSERT EN BATCH (persistirBatch)
    // =========================================================================

    /**
     * Upsert masivo de stock por almacén — llamado al final de persistirBatch().
     * Resuelve todos los almacenes del batch en una sola operación antes del upsert.
     */
    protected function upsertAlmacenesBatch(
        array      $dtosValidos,
        Collection $productos,
        Collection $proveedorProductos,
        int        $proveedorId
    ): void {
        // Recolectar todos los AlmacenStockData únicos del batch
        $todosAlmacenes = [];
        foreach ($dtosValidos as $dto) {
            foreach ($dto->almacenes as $a) {
                $todosAlmacenes[$a->almacenNombre] = $a;
            }
        }

        if (empty($todosAlmacenes)) return;

        // Resolver/crear maestros de golpe — una sola operación para todo el batch
        $almacenMap = $this->resolverAlmacenesMaestros(
            array_values($todosAlmacenes),
            $proveedorId
        );

        $filas = [];

        foreach ($dtosValidos as $dto) {
            if (empty($dto->almacenes)) continue;

            $producto = $productos[$this->claveUnica($dto)] ?? null;
            if (!$producto) continue;

            $pp = $proveedorProductos->firstWhere('producto_id', $producto->id);
            if (!$pp) continue;

            foreach ($dto->almacenes as $almacen) {
                $almacenId = $almacenMap[$almacen->almacenNombre] ?? null;
                if (!$almacenId) continue;

                $filas[] = $this->stockAFila($almacenId, $pp->id, $almacen);
            }
        }

        if (!empty($filas)) {
            DB::table('almacen_producto_stock')->upsert(
                $filas,
                ['proveedor_almacen_id', 'proveedor_producto_id'],
                ['cantidad', 'backorder', 'eta_backorder', 'ultima_actualizacion', 'updated_at']
            );
        }
    }

    // =========================================================================
    // ALMACENES — ACTUALIZACIÓN PAGINADA (actualizarAlmacenes)
    // =========================================================================

    /**
     * Procesa un chunk de DTOs y actualiza el stock por almacén.
     * Los maestros se resuelven una vez por chunk, no por producto.
     */
    protected function actualizarAlmacenesBatch(Collection $chunk, int $proveedorIdBd): array
    {
        $stats = ['total' => 0, 'actualizados' => 0, 'sin_cambios' => 0, 'errores' => 0];

        // Resolver maestros ANTES de la transacción — evita queries repetidas en el loop
        $todosAlmacenes = $chunk
            ->flatMap(fn($dto) => $dto->almacenes)
            ->keyBy(fn($a) => $a->almacenNombre)
            ->values()
            ->all();

        if (empty($todosAlmacenes)) return $stats;

        $almacenMap = $this->resolverAlmacenesMaestros($todosAlmacenes, $proveedorIdBd);

        DB::transaction(function () use ($chunk, $proveedorIdBd, $almacenMap, &$stats) {

            foreach ($chunk as $dto) {
                try {
                    $stats['total']++;

                    if (empty($dto->almacenes)) {
                        $stats['sin_cambios']++;
                        continue;
                    }

                    $pp = $this->buscarProveedorProductoConBloqueo($dto, $proveedorIdBd);
                    if (!$pp) {
                        $stats['errores']++;
                        continue;
                    }

                    $filas = [];
                    foreach ($dto->almacenes as $almacen) {
                        $almacenId = $almacenMap[$almacen->almacenNombre] ?? null;
                        if (!$almacenId) continue;

                        $filas[] = $this->stockAFila($almacenId, $pp->id, $almacen);
                    }

                    if (empty($filas)) {
                        $stats['sin_cambios']++;
                        continue;
                    }

                    DB::table('almacen_producto_stock')->upsert(
                        $filas,
                        ['proveedor_almacen_id', 'proveedor_producto_id'],
                        ['cantidad', 'backorder', 'eta_backorder', 'ultima_actualizacion', 'updated_at']
                    );

                    $stats['actualizados']++;

                } catch (\Throwable $e) {
                    Log::error('[Persistencia] Error actualizando stock de almacén', [
                        'codigo' => $dto->proveedorProductoCodigo ?? null,
                        'error'  => $e->getMessage(),
                    ]);
                    $stats['errores']++;
                }
            }
        }, attempts: 5);

        return $stats;
    }

    // =========================================================================
    // ALMACENES — INDIVIDUAL (persistirProductoIndividual)
    // =========================================================================

    protected function actualizarAlmacenesIndividual(
        ProductoData $dto,
        int          $ppId,
        int          $proveedorId
    ): void {
        if (empty($dto->almacenes)) return;

        $almacenMap = $this->resolverAlmacenesMaestros($dto->almacenes, $proveedorId);

        $filas = [];
        foreach ($dto->almacenes as $almacen) {
            $almacenId = $almacenMap[$almacen->almacenNombre] ?? null;
            if (!$almacenId) continue;

            $filas[] = $this->stockAFila($almacenId, $ppId,$almacen);
        }

        if (!empty($filas)) {
            DB::table('almacen_producto_stock')->upsert(
                $filas,
                ['proveedor_almacen_id', 'proveedor_producto_id'],
                ['cantidad', 'backorder', 'eta_backorder', 'ultima_actualizacion', 'updated_at']
            );
        }
    }

    // =========================================================================
    // ALMACENES — HELPER
    // =========================================================================

    private function stockAFila(int $almacenId, int $ppId, AlmacenStockData $almacen): array
    {
        return [
            'proveedor_almacen_id' => $almacenId,
            'proveedor_producto_id' => $ppId,
            'cantidad'              => $almacen->cantidad,
            'backorder'             => $almacen->backorder,
            'eta_backorder'         => $almacen->etaBackorder,
            'ultima_actualizacion'  => now(),
            'created_at'            => now(),
            'updated_at'            => now(),
        ];
    }

    // =========================================================================
    // PROMOCIONES
    // =========================================================================

    protected function actualizarPromocionesBatch(Collection $chunk, int $proveedorIdBd): array
    {
        $stats = [
            'total'                  => 0,
            'creadas'                => 0,
            'stock_actualizado'      => 0,
            'sin_cambios'            => 0,
            'expiradas_reemplazadas' => 0,
            'expiradas_sin_oferta'   => 0,
            'errores'                => 0,
        ];

        DB::transaction(function () use ($chunk, $proveedorIdBd, &$stats) {
            foreach ($chunk as $dto) {
                try {
                    $stats['total']++;
                    $pp = $this->buscarProveedorProductoConBloqueo($dto, $proveedorIdBd);
                    if (!$pp) { $stats['errores']++; continue; }

                    if (!$this->tienePromocion($dto)) {
                        $expiradas = DB::table('proveedor_producto_promociones')
                            ->where('proveedor_producto_id', $pp->id)
                            ->where('es_oferta', true)
                            ->count();

                        if ($expiradas > 0) {
                            DB::table('proveedor_producto_promociones')
                                ->where('proveedor_producto_id', $pp->id)
                                ->where('es_oferta', true)
                                ->update(['es_oferta' => false, 'updated_at' => now()]);

                            DB::table('proveedor_productos')
                                ->where('id', $pp->id)
                                ->update(['en_oferta' => false]);

                            $stats['expiradas_sin_oferta']++;
                        } else {
                            $stats['sin_cambios']++;
                        }
                        continue;
                    }

                    $filas           = $this->construirFilasPromocion($dto, $pp->id);
                    $clavesEntrantes = array_column($filas, 'clave_promocion');

                    $expiradas = DB::table('proveedor_producto_promociones')
                        ->where('proveedor_producto_id', $pp->id)
                        ->where('es_oferta', true)
                        ->when(
                            !empty($clavesEntrantes),
                            fn($q) => $q->whereNotIn('clave_promocion', $clavesEntrantes)
                        )
                        ->count();

                    if ($expiradas > 0) {
                        DB::table('proveedor_producto_promociones')
                            ->where('proveedor_producto_id', $pp->id)
                            ->where('es_oferta', true)
                            ->whereNotIn('clave_promocion', $clavesEntrantes)
                            ->update(['es_oferta' => false, 'updated_at' => now()]);

                        $stats['expiradas_reemplazadas'] += $expiradas;
                    }

                    foreach ($filas as $nuevaFila) {
                        $ultima = DB::table('proveedor_producto_promociones')
                            ->where('proveedor_producto_id', $pp->id)
                            ->where('clave_promocion', $nuevaFila['clave_promocion'])
                            ->orderBy('id', 'desc')
                            ->lockForUpdate()
                            ->first();

                        if (!$ultima) {
                            DB::table('proveedor_producto_promociones')->insert($nuevaFila);
                            $stats['creadas']++;
                        } elseif ($this->promocionHaCambiado($ultima, $nuevaFila)) {
                            DB::table('proveedor_producto_promociones')
                                ->where('id', $ultima->id)
                                ->update(['es_oferta' => false, 'updated_at' => now()]);

                            DB::table('proveedor_producto_promociones')->insert($nuevaFila);
                            $stats['creadas']++;
                            $stats['expiradas_reemplazadas']++;
                        } else {
                            DB::table('proveedor_producto_promociones')
                                ->where('id', $ultima->id)
                                ->update($this->camposActualizablesPromocion($nuevaFila));

                            $stats['stock_actualizado']++;
                        }
                    }

                    DB::table('proveedor_productos')
                        ->where('id', $pp->id)
                        ->update(['en_oferta' => true]);

                } catch (\Throwable $e) {
                    Log::error('[Persistencia] Error procesando promoción', ['error' => $e->getMessage()]);
                    $stats['errores']++;
                }
            }
        }, attempts: 5);

        return $stats;
    }

    protected function actualizarPromocionIndividualConBloqueo(ProductoData $dto, int $ppId): void
    {
        if (!$this->tienePromocion($dto)) {
            DB::table('proveedor_producto_promociones')
                ->where('proveedor_producto_id', $ppId)
                ->where('es_oferta', true)
                ->update(['es_oferta' => false, 'updated_at' => now()]);

            ProveedorProducto::where('id', $ppId)->update(['en_oferta' => false]);
            return;
        }

        $filas           = $this->construirFilasPromocion($dto, $ppId);
        $clavesEntrantes = array_column($filas, 'clave_promocion');

        DB::table('proveedor_producto_promociones')
            ->where('proveedor_producto_id', $ppId)
            ->where('es_oferta', true)
            ->when(
                !empty($clavesEntrantes),
                fn($q) => $q->whereNotIn('clave_promocion', $clavesEntrantes)
            )
            ->update(['es_oferta' => false, 'updated_at' => now()]);

        foreach ($filas as $nuevaFila) {
            $ultima = DB::table('proveedor_producto_promociones')
                ->where('proveedor_producto_id', $ppId)
                ->where('clave_promocion', $nuevaFila['clave_promocion'])
                ->orderBy('id', 'desc')
                ->lockForUpdate()
                ->first();

            if (!$ultima) {
                DB::table('proveedor_producto_promociones')->insert($nuevaFila);
            } elseif ($this->promocionHaCambiado($ultima, $nuevaFila)) {
                DB::table('proveedor_producto_promociones')
                    ->where('id', $ultima->id)
                    ->update(['es_oferta' => false, 'updated_at' => now()]);

                DB::table('proveedor_producto_promociones')->insert($nuevaFila);
            } else {
                DB::table('proveedor_producto_promociones')
                    ->where('id', $ultima->id)
                    ->update($this->camposActualizablesPromocion($nuevaFila));
            }
        }

        ProveedorProducto::where('id', $ppId)->update(['en_oferta' => true]);
    }

    protected function insertarPromocionesIniciales(
        array      $dtosValidos,
        Collection $productos,
        Collection $proveedorProductos
    ): void {
        $filas = [];

        foreach ($dtosValidos as $dto) {
            if (!$this->tienePromocion($dto)) continue;

            $producto = $productos[$this->claveUnica($dto)] ?? null;
            if (!$producto) continue;

            $pp = $proveedorProductos->firstWhere('producto_id', $producto->id);
            if (!$pp) continue;

            foreach ($this->construirFilasPromocion($dto, $pp->id) as $fila) {
                if (empty($fila['clave_promocion'])) {
                    $fila['clave_promocion'] = 'PROMO-' . $pp->id . '-' . md5(
                        ($fila['descripcion_promocion'] ?? '') . uniqid()
                    );
                }
                $filas[] = $fila;
            }
        }

        if (!empty($filas)) {
            DB::table('proveedor_producto_promociones')->upsert(
                $filas,
                ['proveedor_producto_id', 'clave_promocion'],
                [
                    'total_descuento', 'moneda_descuento', 'tipo_descuento',
                    'moneda_precio_original', 'precio_con_descuento', 'precio_con_descuento_mxn',
                    'tipo_cambio_usado', 'descripcion_promocion',
                    'fecha_inicio', 'expiracion_fecha', 'expiracion_texto',
                    'cantidad_minima', 'disponible_en_promocion',
                    'precio_regular', 'es_oferta', 'updated_at',
                ]
            );
        }
    }

    // =========================================================================
    // HELPERS DE PROMOCIONES
    // =========================================================================

    protected function tienePromocion(ProductoData $dto): bool
    {
        return !empty($dto->promociones);
    }

    protected function construirFilasPromocion(ProductoData $dto, int $ppId): array
    {
        return array_map(
            fn(PromocionData $promo) => $this->promocionAFila($promo, $ppId),
            $dto->promociones
        );
    }

    protected function promocionAFila(PromocionData $promo, int $ppId): array
    {
        return [
            'proveedor_producto_id'    => $ppId,
            'total_descuento'          => $promo->totalDescuento,
            'moneda_descuento'         => $promo->monedaDescuento,
            'tipo_descuento'           => $promo->tipoDescuento,
            'moneda_precio_original'   => $promo->monedaPrecioOriginal,
            'precio_con_descuento'     => $promo->precioConDescuento,
            'precio_con_descuento_mxn' => $promo->precioConDescuentoMxn,
            'tipo_cambio_usado'        => $promo->tipoCambioUsado,
            'clave_promocion'          => $promo->clavePromocion,
            'descripcion_promocion'    => $promo->descripcionPromocion,
            'fecha_inicio'             => $promo->fechaInicio,
            'expiracion_fecha'         => $promo->expiracionFecha,
            'expiracion_texto'         => $promo->expiracionTexto,
            'cantidad_minima'          => $promo->cantidadMinima,
            'disponible_en_promocion'  => $promo->disponibleEnPromocion,
            'precio_regular'           => $promo->precioRegular,
            'es_oferta'                => $promo->esOferta,
            'created_at'               => now(),
            'updated_at'               => now(),
        ];
    }

    protected function camposActualizablesPromocion(array $fila): array
    {
        return [
            'total_descuento'          => $fila['total_descuento'],
            'precio_con_descuento'     => $fila['precio_con_descuento'],
            'precio_con_descuento_mxn' => $fila['precio_con_descuento_mxn'],
            'tipo_cambio_usado'        => $fila['tipo_cambio_usado'],
            'disponible_en_promocion'  => $fila['disponible_en_promocion'],
            'precio_regular'           => $fila['precio_regular'],
            'expiracion_fecha'         => $fila['expiracion_fecha'],
            'expiracion_texto'         => $fila['expiracion_texto'],
            'updated_at'               => now(),
        ];
    }

    protected function promocionHaCambiado($ultima, array $nuevaFila): bool
    {
        if ($ultima->clave_promocion === null || $nuevaFila['clave_promocion'] === null) {
            return true;
        }

        if ($ultima->clave_promocion !== $nuevaFila['clave_promocion']) {
            return true;
        }

        foreach (['descripcion_promocion', 'moneda_precio_original', 'tipo_descuento', 'fecha_inicio'] as $campo) {
            if ($ultima->{$campo} !== $nuevaFila[$campo]) {
                return true;
            }
        }

        return false;
    }

    // =========================================================================
    // PRECIOS
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

                    if (!$registro || $this->precioHaCambiado($registro, $dto)) {
                        if ($registro) {
                            $actualizacion = array_merge(
                                ['precio_anterior' => $registro->precio_actual],
                                $this->datosPrecioDesdeDto($dto),
                                ['ultima_actualizacion' => now(), 'updated_at' => now()]
                            );

                            DB::table('proveedor_producto_precios')
                                ->where('proveedor_producto_id', $pp->id)
                                ->update($actualizacion);
                        } else {
                            $fila = array_merge($this->datosPrecioDesdeDto($dto), [
                                'proveedor_producto_id' => $pp->id,
                                'precio_anterior'       => null,
                                'ultima_actualizacion'  => now(),
                                'created_at'            => now(),
                                'updated_at'            => now(),
                            ]);

                            DB::table('proveedor_producto_precios')->insert($fila);
                        }

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

        if (!$registro || $this->precioHaCambiado($registro, $dto)) {
            if (!$registro) {
                DB::table('proveedor_producto_precios')->insert(array_merge(
                    $this->datosPrecioDesdeDto($dto),
                    [
                        'proveedor_producto_id' => $ppId,
                        'precio_anterior'       => null,
                        'ultima_actualizacion'  => now(),
                        'created_at'            => now(),
                        'updated_at'            => now(),
                    ]
                ));
            } else {
                DB::table('proveedor_producto_precios')
                    ->where('proveedor_producto_id', $ppId)
                    ->update(array_merge(
                        ['precio_anterior' => $registro->precio_actual],
                        $this->datosPrecioDesdeDto($dto),
                        ['ultima_actualizacion' => now(), 'updated_at' => now()]
                    ));
            }
        }
    }

    // =========================================================================
    // STOCK
    // =========================================================================

    protected function actualizarStockBatch(Collection $chunk, int $proveedorIdBd): array
    {
        $stats = ['total' => 0, 'actualizados' => 0, 'sin_cambios' => 0, 'errores' => 0];

        DB::transaction(function () use ($chunk, $proveedorIdBd, &$stats) {
            $ids       = [];
            $stockData = [];

            foreach ($chunk as $dto) {
                try {
                    $stats['total']++;
                    $pp = $this->buscarProveedorProductoConBloqueo($dto, $proveedorIdBd);
                    if (!$pp) { $stats['errores']++; continue; }

                    $stockHaCambiado = (int) $pp->stock_total    !== (int) $dto->stockTotal;

                    if ($stockHaCambiado) {
                        $ids[]              = $pp->id;
                        $stockData[$pp->id] = ['stock_total' => $dto->stockTotal];
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
                $this->actualizarStockMasivo($ids, $stockData);
            }
        }, attempts: 5);

        return $stats;
    }

    protected function actualizarStockMasivo(array $ids, array $stockData): void
    {
        sort($ids);

        $cases    = '';
        $bindings = [];

        foreach ($ids as $id) {
            $cases      .= " WHEN ? THEN ?";
            $bindings[]  = $id;
            $bindings[]  = $stockData[$id]['stock_total'];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        DB::statement(
            "UPDATE proveedor_productos
            SET stock_total          = CASE id {$cases} END,
                ultima_actualizacion = NOW(),
                updated_at           = NOW()
            WHERE id IN ({$placeholders})",
            array_merge($bindings, $ids)
        );
    }

    protected function actualizarStockIndividualConBloqueo(ProductoData $dto, int $ppId): void
    {
        $pp = ProveedorProducto::where('id', $ppId)->lockForUpdate()->first();

        if ($pp && ((int) $pp->stock_total !== (int) $dto->stockTotal)) {
            $pp->update([
                'stock_total'                => $dto->stockTotal,
                'ultima_actualizacion' => now(),
            ]);
        }
    }

    // =========================================================================
    // PRODUCTOS Y PROVEEDOR_PRODUCTOS
    // =========================================================================

    protected function upsertProductos(array $dtosValidos): Collection
    {
        if (empty($dtosValidos)) return collect();

        $filas = [];

        foreach ($dtosValidos as $dto) {
            $grupoNombre = $this->procesarNombreGrupo($dto->grupoNombre);

            $filas[] = [
                'upc'                 => $this->claveUnica($dto),
                'nombre'              => $dto->nombre,
                'descripcion'         => $dto->descripcion,
                'descripcion_tecnica' => $dto->descripcionTecnica,
                'categoria_id'        => $this->cache['categorias'][$dto->categoriaNombre]                    ?? null,
                'sub_categoria_id'    => $this->cache['subcategorias'][$dto->subcategoriaNombre ?? 'General'] ?? null,
                'familia_id'          => $this->cache['familias'][$dto->familiaNombre           ?? 'General'] ?? null,
                'grupo_id'            => $this->cache['grupos'][$grupoNombre]                                 ?? null,
                'marca_id'            => $this->cache['marcas'][$dto->marcaNombre               ?? 'General'] ?? null,
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

    protected function upsertProveedorProductos(
        array      $dtosValidos,
        Collection $productos,
        int        $proveedorIdBd
    ): Collection {
        $filas = [];

        foreach ($dtosValidos as $dto) {
            $producto = $productos[$this->claveUnica($dto)] ?? null;
            if (!$producto) continue;

            $filas[] = [
                'proveedor_id'          => $proveedorIdBd,
                'producto_id'           => $producto->id,
                'proveedor_producto_id' => $dto->proveedorProductoId,
                'codigo_proveedor'      => $dto->proveedorProductoCodigo,
                'stock_total'              => $dto->stockTotal,
                'garantia'              => $dto->garantia,
                'en_oferta'             => $dto->enOferta,
                'ultima_actualizacion'  => now(),
                'updated_at'            => now(),
                'created_at'            => now(),
            ];
        }

        if (!empty($filas)) {
            DB::table('proveedor_productos')->upsert(
                $filas,
                ['proveedor_id', 'producto_id'],
                [
                    'proveedor_producto_id', 'codigo_proveedor',
                    'stock_total', 'garantia', 'en_oferta',
                    'ultima_actualizacion', 'updated_at',
                ]
            );
        }

        return ProveedorProducto::where('proveedor_id', $proveedorIdBd)
            ->whereIn('producto_id', $productos->pluck('id'))
            ->get()
            ->keyBy(fn($pp) => $pp->proveedor_id . '-' . $pp->producto_id);
    }

    protected function buscarOCrearProductoConBloqueo(ProductoData $dto): Producto
    {
        $clave    = $this->claveUnica($dto);
        $producto = Producto::where('upc', $clave)->lockForUpdate()->first();

        $grupoNombre = $this->procesarNombreGrupo($dto->grupoNombre);

        $datos = [
            'upc'                 => $clave,
            'nombre'              => $dto->nombre,
            'descripcion'         => $dto->descripcion,
            'descripcion_tecnica' => $dto->descripcionTecnica,
            'categoria_id'        => $this->cache['categorias'][$dto->categoriaNombre]                    ?? null,
            'sub_categoria_id'    => $this->cache['subcategorias'][$dto->subcategoriaNombre ?? 'General'] ?? null,
            'familia_id'          => $this->cache['familias'][$dto->familiaNombre           ?? 'General'] ?? null,
            'grupo_id'            => $this->cache['grupos'][$grupoNombre]                                 ?? null,
            'marca_id'            => $this->cache['marcas'][$dto->marcaNombre               ?? 'General'] ?? null,
            'codigo_fabricante'   => $dto->codigoFabricante,
            'codigo_barras'       => $dto->codigoBarras,
        ];

        if (!$producto) return Producto::create($datos);

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

    protected function buscarOCrearProveedorProductoConBloqueo(
        ProductoData $dto,
        int          $productoId,
        int          $proveedorIdBd
    ): ProveedorProducto {
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
            'stock_total'           => $dto->stockTotal,
            'garantia'              => $dto->garantia,
            'en_oferta'             => $dto->enOferta,
            'ultima_actualizacion'  => now(),
        ]);
    }

    protected function insertarPreciosIniciales(
        array      $dtosValidos,
        Collection $productos,
        Collection $proveedorProductos
    ): void {
        $filas = [];

        foreach ($dtosValidos as $dto) {
            if (!is_numeric($dto->precioActual)) continue;

            $producto = $productos[$this->claveUnica($dto)] ?? null;
            if (!$producto) continue;

            $pp = $proveedorProductos->firstWhere('producto_id', $producto->id);
            if (!$pp) continue;

            $filas[] = array_merge(
                $this->datosPrecioDesdeDto($dto),
                [
                    'proveedor_producto_id' => $pp->id,
                    'precio_anterior'       => null,
                    'ultima_actualizacion'  => now(),
                    'created_at'            => now(),
                    'updated_at'            => now(),
                ]
            );
        }

        if (!empty($filas)) {
            DB::table('proveedor_producto_precios')->upsert(
                $filas,
                ['proveedor_producto_id'],
                [
                    'moneda_venta', 'precio_venta', 'precio_anterior',
                    'precio_base_producto', 'moneda_base_producto',
                    'precio_recomendado_proveedor', 'porcentaje_utilidad',
                    'tipo_cambio_usado_mxn', 'ultima_actualizacion', 'updated_at',
                ]
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
            DB::table('producto_imagenes')->upsert(
                $filas,
                ['url_imagen', 'producto_id'],
                ['updated_at']
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

    protected function datosPrecioDesdeDto(ProductoData $dto): array
    {
        return [
            'moneda_venta'                => $dto->moneda,
            'precio_venta'                => $dto->precioActual,
            'precio_base_producto'        => $dto->precioBaseProducto,
            'moneda_base_producto'        => $dto->monedaBaseProducto,
            'precio_recomendado_proveedor'=> $dto->precioRecomendadoProveedor,
            'porcentaje_utilidad'         => $dto->porcentajeUtilidadAplicado,
            'tipo_cambio_usado_mxn'       => $dto->tipoCambioUsadoMxn,
        ];
    }

    protected function precioHaCambiado($registro, ProductoData $dto): bool
    {
        if (!$registro) return true;
        if ((float)  $registro->precio_actual              !== (float)  $dto->precioActual)              return true;
        if (          $registro->moneda                    !==           $dto->moneda)                   return true;
        if ((string) $registro->precio_base_producto       !== (string) $dto->precioBaseProducto)        return true;
        if (          $registro->moneda_base_producto      !==           $dto->monedaBaseProducto)       return true;
        if ((string) $registro->precio_recomendado_proveedor !== (string) $dto->precioRecomendadoProveedor) return true;
        if ((int)    $registro->porcentaje_utilidad_aplicado !== (int)  $dto->porcentajeUtilidadAplicado) return true;
        if ((string) $registro->tipo_cambio_usado_mxn      !== (string) $dto->tipoCambioUsadoMxn)        return true;

        return false;
    }

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

    protected function precioEsValido(mixed $precio): bool
    {
        if ($precio === null || $precio === '') return false;

        $limpio = preg_replace('/[^0-9.]/', '', (string) $precio);

        if (!is_numeric($limpio) || (float) $limpio <= 0) return false;

        if ((float) $limpio > 9_999_999) {
            Log::warning('[Persistencia] Precio sospechosamente alto descartado', ['precio' => $precio]);
            return false;
        }

        return true;
    }

    protected function monedaEsValida(mixed $moneda): bool
    {
        if ($moneda === null || trim((string) $moneda) === '') return false;

        return in_array(strtoupper(trim((string) $moneda)), ['MXN', 'USD', 'EUR'], true);
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

    protected function buscarProveedorProducto(ProductoData $dto, int $proveedorIdBd)
    {
        return DB::table('proveedor_productos as pp')
            ->join('productos as p', 'pp.producto_id', '=', 'p.id')
            ->where('pp.proveedor_id', $proveedorIdBd)
            ->where('p.upc', $this->claveUnica($dto))
            ->select('pp.*')
            ->first();
    }

    protected function procesarNombreGrupo(?string $grupoNombre): string
    {
        if (empty($grupoNombre)) return 'General';

        $partes = explode('/', str_replace('\/', '/', $grupoNombre));
        $nombre = trim($partes[0]);

        return !empty($nombre) ? substr($nombre, 0, 255) : 'General';
    }

    protected function ejecutarConReintentos(
        callable  $op,
        int       $maxIntentos = self::MAX_RETRIES,
        ?callable $onError     = null
    ) {
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
        return str_contains($e->getMessage(), 'Deadlock')
            || str_contains($e->getMessage(), 'try restarting transaction')
            || $e->getCode() === '40001'
            || $e->getCode() === 1213;
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