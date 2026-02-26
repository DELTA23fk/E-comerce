<?php

namespace App\Services\Sync;

use App\Contratos\ProveedorSyncInterface;
use App\Models\Proveedor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

class ProductoSyncOrchestrator
{
    /**
     * Mapa de clave-proveedor → servicio de sincronización.
     *
     * @var array<string, ProveedorSyncInterface>
     */
    private array $proveedores = [];

    public function __construct(
        private readonly ProductoPersistenceService $persistencia,
        iterable $proveedores = [],
    ) {
        foreach ($proveedores as $servicio) {
            $this->registrar($servicio);
        }
    }

    // =========================================================================
    // REGISTRO DE PROVEEDORES
    // =========================================================================

    public function registrar(ProveedorSyncInterface $servicio): static
    {
        $this->proveedores[$servicio->getProveedorClave()] = $servicio;
        return $this;
    }

    // =========================================================================
    // SINCRONIZACIÓN INICIAL
    // =========================================================================

    /**
     * Persiste una página del catálogo y devuelve metadatos de paginación
     * para que el Job/Command decida si continuar con la siguiente.
     *
     * @return array{pagina_actual: int, total_paginas: int, hay_mas: bool, productos_procesados: int}|null
     */
    public function syncInicial(string $proveedorClave, array $filtros = [], int $pagina = 1): ?array
    {
        $servicio      = $this->resolver($proveedorClave);
        $proveedorIdBd = $this->resolverProveedorIdBd($proveedorClave);

        Log::info("[Orchestrator] syncInicial {$proveedorClave} página {$pagina}");

        $resultado = $servicio->obtenerPaginaDeProductos($filtros, $pagina);

        if ($resultado->estaVacio()) {
            Log::info("[Orchestrator] No hay productos en página {$pagina}");
            return null;
        }

        $this->persistencia->persistirBatch($resultado->productos->all(), $proveedorIdBd);

        return [
            'pagina_actual'        => $resultado->paginaActual,
            'total_paginas'        => $resultado->totalPaginas,
            'hay_mas'              => $resultado->hayMasPaginas,
            'productos_procesados' => $resultado->productos->count(),
        ];
    }

    /**
     * Igual que syncInicial pero devuelve información enriquecida para
     * comandos interactivos: omitidos, siguiente página y comando sugerido.
     *
     * @return array{
     *   pagina_actual: int,
     *   total_paginas: int,
     *   hay_mas: bool,
     *   productos_recibidos: int,
     *   productos_persistidos: int,
     *   omitidos: int,
     *   siguiente_pagina: int|null,
     *   comando_siguiente: string|null,
     * }|null
     */
    public function syncInicialPorPagina(string $proveedorClave, array $filtros = [], int $pagina = 1): ?array
    {
        $servicio      = $this->resolver($proveedorClave);
        $proveedorIdBd = $this->resolverProveedorIdBd($proveedorClave);

        Log::info("[Orchestrator] syncInicialPorPagina {$proveedorClave} página {$pagina}");

        $resultado = $servicio->obtenerPaginaDeProductos($filtros, $pagina);

        if ($resultado->estaVacio()) {
            Log::info("[Orchestrator] No hay productos en página {$pagina}");
            return null;
        }

        $todosLosProductos = $resultado->productos->all();
        $recibidos         = count($todosLosProductos);
        $validos           = $this->contarDtosValidos($todosLosProductos);
        $omitidos          = $recibidos - $validos;

        $this->persistencia->persistirBatch($todosLosProductos, $proveedorIdBd);

        $hayMas          = $resultado->hayMasPaginas;
        $siguientePagina = $hayMas ? $pagina + 1 : null;

        Log::info("[Orchestrator] Página {$pagina}/{$resultado->totalPaginas} — recibidos: {$recibidos}, omitidos: {$omitidos}");

        return [
            'pagina_actual'         => $resultado->paginaActual,
            'total_paginas'         => $resultado->totalPaginas,
            'hay_mas'               => $hayMas,
            'productos_recibidos'   => $recibidos,
            'productos_persistidos' => $validos,
            'omitidos'              => $omitidos,
            'siguiente_pagina'      => $siguientePagina,
            'comando_siguiente'     => $siguientePagina
                ? "php artisan sync:productos --proveedor={$proveedorClave} --page={$siguientePagina}"
                : null,
        ];
    }

    /**
     * Itera automáticamente todas las páginas y persiste el catálogo completo.
     *
     * @return array{total_paginas: int, total_productos: int}
     */
    public function syncInicialCompleto(string $proveedorClave, array $filtros = []): array
    {
        $pagina         = 1;
        $totalProductos = 0;
        $totalPaginas   = 0;

        do {
            $info = $this->syncInicial($proveedorClave, $filtros, $pagina);

            if (!$info) break;

            $totalProductos += $info['productos_procesados'];
            $totalPaginas    = $info['total_paginas'];
            $pagina++;

            Log::info("[Orchestrator] Progreso {$proveedorClave}: {$pagina}/{$totalPaginas}");

        } while ($info['hay_mas']);

        return ['total_paginas' => $totalPaginas, 'total_productos' => $totalProductos];
    }

    // =========================================================================
    // ACTUALIZACIONES ESPECÍFICAS
    // =========================================================================

    /**
     * Actualiza solo precios del proveedor indicado, página a página.
     *
     * Proveedor unificado  → itera obtenerProductosParaActualizacion()
     * Proveedor separado   → itera obtenerProductosConPrecioActualizado()
     *
     * Cada página se persiste antes de pedir la siguiente:
     * memoria acotada a ~500 productos en todo momento.
     *
     * @return array{total: int, actualizados: int, sin_cambios: int, errores: int, paginas: int, proveedor: string}
     */
    public function syncPrecios(string $proveedorClave, array $filtros = []): array
    {
        $servicio      = $this->resolver($proveedorClave);
        $proveedorIdBd = $this->resolverProveedorIdBd($proveedorClave);

        Log::info("[Orchestrator] syncPrecios {$proveedorClave}");

        return $this->iterar(
            contexto: "syncPrecios {$proveedorClave}",
            statsBase: ['total' => 0, 'actualizados' => 0, 'sin_cambios' => 0, 'errores' => 0],
            obtenerPagina: $servicio->soportaConsultaUnificada()
                ? fn(int $p) => $servicio->obtenerProductosParaActualizacion($p)
                : fn(int $p) => $servicio->obtenerProductosConPrecioActualizado($p),
            persistirPagina: fn($productos) => $this->persistencia->actualizarPrecios($productos, $proveedorIdBd),
            proveedor: $proveedorClave,
        );
    }

    /**
     * Actualiza solo stock del proveedor indicado, página a página.
     *
     * Proveedor unificado  → itera obtenerProductosParaActualizacion()
     * Proveedor separado   → itera obtenerProductosConStockActualizado()
     *
     * @return array{total: int, actualizados: int, sin_cambios: int, errores: int, paginas: int, proveedor: string}
     */
    public function syncStock(string $proveedorClave, array $filtros = []): array
    {
        $servicio      = $this->resolver($proveedorClave);
        $proveedorIdBd = $this->resolverProveedorIdBd($proveedorClave);

        Log::info("[Orchestrator] syncStock {$proveedorClave}");

        return $this->iterar(
            contexto: "syncStock {$proveedorClave}",
            statsBase: ['total' => 0, 'actualizados' => 0, 'sin_cambios' => 0, 'errores' => 0],
            obtenerPagina: $servicio->soportaConsultaUnificada()
                ? fn(int $p) => $servicio->obtenerProductosParaActualizacion($p)
                : fn(int $p) => $servicio->obtenerProductosConStockActualizado($p),
            persistirPagina: fn($productos) => $this->persistencia->actualizarStock($productos, $proveedorIdBd),
            proveedor: $proveedorClave,
        );
    }

    /**
     * Actualiza solo promociones del proveedor indicado, página a página.
     *
     * Proveedor unificado  → itera obtenerProductosParaActualizacion()
     * Proveedor separado   → itera obtenerProductosEnPromocion()
     *
     * @return array{total: int, creadas: int, stock_actualizado: int, sin_cambios: int, expiradas_reemplazadas: int, expiradas_sin_oferta: int, errores: int, paginas: int, proveedor: string}
     */
    public function syncPromociones(string $proveedorClave, array $filtros = []): array
    {
        $servicio      = $this->resolver($proveedorClave);
        $proveedorIdBd = $this->resolverProveedorIdBd($proveedorClave);

        Log::info("[Orchestrator] syncPromociones {$proveedorClave}");

        return $this->iterar(
            contexto: "syncPromociones {$proveedorClave}",
            statsBase: [
                'total'                  => 0,
                'creadas'                => 0,
                'stock_actualizado'      => 0,
                'sin_cambios'            => 0,
                'expiradas_reemplazadas' => 0,
                'expiradas_sin_oferta'   => 0,
                'errores'                => 0,
            ],
            obtenerPagina: $servicio->soportaConsultaUnificada()
                ? fn(int $p) => $servicio->obtenerProductosParaActualizacion($p)
                : fn(int $p) => $servicio->obtenerProductosEnPromocion($p),
            persistirPagina: fn($productos) => $this->persistencia->actualizarPromociones($productos, $proveedorIdBd),
            proveedor: $proveedorClave,
        );
    }

    /**
     * Actualiza precio + stock + promociones del proveedor, página a página.
     *
     * ─── PROVEEDOR UNIFICADO (ej. CVA) ────────────────────────────────────────
     *   1 HTTP por ciclo → misma página pasa a los 3 métodos de persistencia.
     *   Memoria: ~500 productos en RAM en todo momento.
     *   HTTP total: N páginas.
     *
     * ─── PROVEEDOR CON ENDPOINTS SEPARADOS (ej. Exel, Syscom) ─────────────────
     *   3 loops independientes de paginación, uno por tipo.
     *   Equivalente a llamar syncPrecios + syncStock + syncPromociones.
     *   HTTP total: 3×N páginas.
     *
     * @return array{precios: array, stock: array, promociones: array}
     */
    public function syncTodo(string $proveedorClave, array $filtros = []): array
    {
        $servicio      = $this->resolver($proveedorClave);
        $proveedorIdBd = $this->resolverProveedorIdBd($proveedorClave);

        if ($servicio->soportaConsultaUnificada()) {
            Log::info("[Orchestrator] syncTodo UNIFICADO {$proveedorClave} — 1 HTTP por página, 3 tipos por ciclo");

            return $this->syncTodoUnificado($servicio, $proveedorClave, $proveedorIdBd);
        }

        Log::info("[Orchestrator] syncTodo SEPARADO {$proveedorClave} — 3 loops independientes");

        // Reutiliza $servicio y $proveedorIdBd ya resueltos
        return [
            'precios'     => $this->iterar(
                contexto: "syncPrecios {$proveedorClave}",
                statsBase: ['total' => 0, 'actualizados' => 0, 'sin_cambios' => 0, 'errores' => 0],
                obtenerPagina: fn(int $p) => $servicio->obtenerProductosConPrecioActualizado($p),
                persistirPagina: fn($productos) => $this->persistencia->actualizarPrecios($productos, $proveedorIdBd),
                proveedor: $proveedorClave,
            ),
            'stock'       => $this->iterar(
                contexto: "syncStock {$proveedorClave}",
                statsBase: ['total' => 0, 'actualizados' => 0, 'sin_cambios' => 0, 'errores' => 0],
                obtenerPagina: fn(int $p) => $servicio->obtenerProductosConStockActualizado($p),
                persistirPagina: fn($productos) => $this->persistencia->actualizarStock($productos, $proveedorIdBd),
                proveedor: $proveedorClave,
            ),
            'promociones' => $this->iterar(
                contexto: "syncPromociones {$proveedorClave}",
                statsBase: [
                    'total'                  => 0,
                    'creadas'                => 0,
                    'stock_actualizado'      => 0,
                    'sin_cambios'            => 0,
                    'expiradas_reemplazadas' => 0,
                    'expiradas_sin_oferta'   => 0,
                    'errores'                => 0,
                ],
                obtenerPagina: fn(int $p) => $servicio->obtenerProductosEnPromocion($p),
                persistirPagina: fn($productos) => $this->persistencia->actualizarPromociones($productos, $proveedorIdBd),
                proveedor: $proveedorClave,
            ),
        ];
    }

    /**
     * Sincronización completa de un único artículo (ideal para webhooks).
     */
    public function syncArticulo(string $proveedorClave, string $idExterno): array
    {
        $servicio      = $this->resolver($proveedorClave);
        $proveedorIdBd = $this->resolverProveedorIdBd($proveedorClave);

        Log::info("[Orchestrator] syncArticulo {$proveedorClave}:{$idExterno}");

        $dto = $servicio->obtenerProductoPorId($idExterno);

        if (!$dto) {
            return [
                'exito' => false,
                'error' => "Artículo '{$idExterno}' no encontrado en proveedor '{$proveedorClave}'",
            ];
        }

        return array_merge(
            $this->persistencia->persistirProductoIndividual($dto, $proveedorIdBd),
            ['proveedor' => $proveedorClave, 'id_externo' => $idExterno]
        );
    }

    /**
     * Ejecuta syncTodo para TODOS los proveedores registrados.
     *
     * @return array<string, array>
     */
    public function syncTodosLosProveedores(array $filtros = []): array
    {
        $resultados = [];

        foreach (array_keys($this->proveedores) as $clave) {
            try {
                Log::info("[Orchestrator] Iniciando sync completo de {$clave}");
                $resultados[$clave] = $this->syncTodo($clave, $filtros);
            } catch (\Throwable $e) {
                Log::error("[Orchestrator] Error en sync de {$clave}", ['error' => $e->getMessage()]);
                $resultados[$clave] = ['error' => $e->getMessage()];
            }
        }

        return $resultados;
    }

    // =========================================================================
    // UTILIDADES PÚBLICAS
    // =========================================================================

    /**
     * Lista las claves de todos los proveedores registrados.
     *
     * @return string[]
     */
    public function proveedoresRegistrados(): array
    {
        return array_keys($this->proveedores);
    }

    /**
     * Indica si un proveedor específico soporta consulta unificada.
     * Usado por DespacharActualizacionesJob para optimizar el tipo de job.
     */
    public function proveedorSoportaConsultaUnificada(string $clave): bool
    {
        $servicio = $this->proveedores[strtolower(trim($clave))] ?? null;

        return $servicio?->soportaConsultaUnificada() ?? false;
    }

    // =========================================================================
    // HELPERS INTERNOS
    // =========================================================================

    /**
     * Loop de paginación genérico para actualizaciones.
     *
     * Llama a $obtenerPagina($n) por cada ciclo, pasa los productos a
     * $persistirPagina() y acumula los stats hasta que no haya más páginas.
     *
     * Usado por syncPrecios, syncStock, syncPromociones y syncTodo (separado).
     *
     * @param  callable(int): SyncPageResult  $obtenerPagina
     * @param  callable(Collection): array    $persistirPagina
     */
    private function iterar(
        string $contexto,
        array $statsBase,
        callable $obtenerPagina,
        callable $persistirPagina,
        string $proveedor,
    ): array {
        $stats        = $statsBase;
        $pagina       = 1;
        $totalPaginas = null;

        do {
            $resultado = $obtenerPagina($pagina);

            if ($resultado->estaVacio()) {
                Log::info("[Orchestrator] {$contexto} — sin productos en pág. {$pagina}, deteniendo");
                break;
            }

            if ($totalPaginas === null) {
                $totalPaginas = $resultado->totalPaginas;
            }

            $statsPagina = $persistirPagina($resultado->productos);
            $this->sumarStats($stats, $statsPagina);

            Log::info("[Orchestrator] {$contexto} — pág. {$pagina}/{$totalPaginas}", [
                'productos' => $resultado->productos->count(),
                'stats'     => $statsPagina,
            ]);

            $pagina++;

            if ($resultado->hayMasPaginas) {
                sleep(1);
            }

        } while ($resultado->hayMasPaginas);

        return array_merge($stats, [
            'paginas'   => $pagina - 1,
            'proveedor' => $proveedor,
        ]);
    }

    /**
     * Loop de syncTodo para proveedores unificados.
     *
     * Por cada página: 1 HTTP → misma Collection pasa a precios + stock + promos.
     * Stats acumulados de todas las páginas, separados por tipo.
     */
    private function syncTodoUnificado(
        ProveedorSyncInterface $servicio,
        string $proveedorClave,
        int $proveedorIdBd,
    ): array {
        $statsPrecios = ['total' => 0, 'actualizados' => 0, 'sin_cambios' => 0, 'errores' => 0];
        $statsStock   = ['total' => 0, 'actualizados' => 0, 'sin_cambios' => 0, 'errores' => 0];
        $statsPromos  = [
            'total'                  => 0,
            'creadas'                => 0,
            'stock_actualizado'      => 0,
            'sin_cambios'            => 0,
            'expiradas_reemplazadas' => 0,
            'expiradas_sin_oferta'   => 0,
            'errores'                => 0,
        ];

        $pagina       = 1;
        $totalPaginas = null;

        do {
            $resultado = $servicio->obtenerProductosParaActualizacion($pagina);

            if ($resultado->estaVacio()) {
                Log::info("[Orchestrator] syncTodo {$proveedorClave} — sin productos en pág. {$pagina}, deteniendo");
                break;
            }

            if ($totalPaginas === null) {
                $totalPaginas = $resultado->totalPaginas;
            }

            // La misma colección a los 3 métodos — sin HTTP adicional
            $productos = $resultado->productos;

            $this->sumarStats($statsPrecios, $this->persistencia->actualizarPrecios($productos, $proveedorIdBd));
            $this->sumarStats($statsStock,   $this->persistencia->actualizarStock($productos, $proveedorIdBd));
            $this->sumarStats($statsPromos,  $this->persistencia->actualizarPromociones($productos, $proveedorIdBd));

            Log::info("[Orchestrator] syncTodo {$proveedorClave} — pág. {$pagina}/{$totalPaginas}", [
                'productos' => $productos->count(),
                'precios'   => $statsPrecios,
                'stock'     => $statsStock,
                'promos'    => $statsPromos,
            ]);

            $pagina++;

            if ($resultado->hayMasPaginas) {
                sleep(1);
            }

        } while ($resultado->hayMasPaginas);

        $procesadas = $pagina - 1;

        return [
            'precios'     => array_merge($statsPrecios, ['paginas' => $procesadas, 'proveedor' => $proveedorClave]),
            'stock'       => array_merge($statsStock,   ['paginas' => $procesadas, 'proveedor' => $proveedorClave]),
            'promociones' => array_merge($statsPromos,  ['paginas' => $procesadas, 'proveedor' => $proveedorClave]),
        ];
    }

    /**
     * Resuelve el servicio de proveedor por su clave.
     *
     * @throws \InvalidArgumentException Si el proveedor no está registrado
     */
    private function resolver(string $clave): ProveedorSyncInterface
    {
        $clave = strtolower(trim($clave));

        if (!isset($this->proveedores[$clave])) {
            throw new \InvalidArgumentException(
                "Proveedor '{$clave}' no registrado. Disponibles: " . implode(', ', $this->proveedoresRegistrados())
            );
        }

        return $this->proveedores[$clave];
    }

    /**
     * Obtiene el ID de BD del proveedor por su clave.
     * Usa caché para evitar queries repetidas en loops de paginación.
     *
     * @throws \RuntimeException Si el proveedor no existe en BD
     */
    private function resolverProveedorIdBd(string $clave): int
    {
        return Cache::remember("proveedor_id_{$clave}", 3600, function () use ($clave) {
            $proveedor = Proveedor::where('codigo_proveedor', $clave)->first();

            if (!$proveedor) {
                throw new \RuntimeException(
                    "Proveedor '{$clave}' no encontrado en BD. Verifica la tabla `proveedores`."
                );
            }

            return $proveedor->id;
        });
    }

    /**
     * Acumula stats sumando cada clave numérica.
     */
    private function sumarStats(array &$base, ?array $nuevo): void
    {
        if (!$nuevo) return;
        foreach ($nuevo as $key => $val) {
            if (isset($base[$key]) && is_numeric($val)) {
                $base[$key] += $val;
            }
        }
    }

    /**
     * Cuenta cuántos DTOs pasarían la validación de precio y moneda.
     * Permite calcular 'omitidos' sin duplicar la lógica de filtrado.
     */
    private function contarDtosValidos(array $dtos): int
    {
        return count(array_filter($dtos, fn($dto) =>
            ($dto->upc ?? $dto->codigoBarras ?? null) !== null &&
            is_numeric(preg_replace('/[^0-9.]/', '', (string) ($dto->precioActual ?? ''))) &&
            (float) preg_replace('/[^0-9.]/', '', (string) ($dto->precioActual ?? '')) > 0 &&
            in_array(strtoupper(trim((string) ($dto->moneda ?? ''))), ['MXN', 'USD', 'EUR'], true)
        ));
    }
}