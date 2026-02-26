<?php

namespace App\Services\Sync;

use App\Contratos\ProveedorSyncInterface;
use App\Models\Proveedor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProductoSyncOrchestrator
{
    /**
     * Mapa de clave-proveedor → servicio de sincronización.
     * Ejemplo: ['cva' => CvaSyncService, 'exel' => ExelSyncService]
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
     * Actualiza solo precios del proveedor indicado.
     *
     * Si el proveedor soporta consulta unificada, se obtienen los productos
     * una vez y se pasan directamente a la persistencia, sin hacer llamadas
     * HTTP adicionales. Si no, se llama al endpoint específico de precios.
     */
    public function syncPrecios(string $proveedorClave, array $filtros = []): array
    {
        $servicio      = $this->resolver($proveedorClave);
        $proveedorIdBd = $this->resolverProveedorIdBd($proveedorClave);

        Log::info("[Orchestrator] syncPrecios {$proveedorClave}");

        $productos = $servicio->soportaConsultaUnificada()
            ? $servicio->obtenerProductosParaActualizacion($filtros)
            : $servicio->obtenerProductosConPrecioActualizado($filtros);

        return array_merge(
            $this->persistencia->actualizarPrecios($productos, $proveedorIdBd),
            ['proveedor' => $proveedorClave]
        );
    }

    /**
     * Actualiza solo stock del proveedor indicado.
     *
     * Misma lógica de optimización que syncPrecios.
     */
    public function syncStock(string $proveedorClave, array $filtros = []): array
    {
        $servicio      = $this->resolver($proveedorClave);
        $proveedorIdBd = $this->resolverProveedorIdBd($proveedorClave);

        Log::info("[Orchestrator] syncStock {$proveedorClave}");

        $productos = $servicio->soportaConsultaUnificada()
            ? $servicio->obtenerProductosParaActualizacion($filtros)
            : $servicio->obtenerProductosConStockActualizado($filtros);

        return array_merge(
            $this->persistencia->actualizarStock($productos, $proveedorIdBd),
            ['proveedor' => $proveedorClave]
        );
    }

    /**
     * Actualiza solo promociones del proveedor indicado.
     *
     * Misma lógica de optimización que syncPrecios.
     */
    public function syncPromociones(string $proveedorClave, array $filtros = []): array
    {
        $servicio      = $this->resolver($proveedorClave);
        $proveedorIdBd = $this->resolverProveedorIdBd($proveedorClave);

        Log::info("[Orchestrator] syncPromociones {$proveedorClave}");

        $productos = $servicio->soportaConsultaUnificada()
            ? $servicio->obtenerProductosParaActualizacion($filtros)
            : $servicio->obtenerProductosEnPromocion($filtros);

        return array_merge(
            $this->persistencia->actualizarPromociones($productos, $proveedorIdBd),
            ['proveedor' => $proveedorClave]
        );
    }

    /**
     * Actualiza precio + stock + promociones del proveedor.
     *
     * OPTIMIZACIÓN — proveedor con consulta unificada (ej. CVA):
     *   obtenerProductosParaActualizacion() se llama UNA sola vez.
     *   La misma colección se pasa a los tres métodos de persistencia.
     *   = 1 recorrido HTTP en vez de 3.
     *
     * PROVEEDOR con endpoints separados (ej. Exel, Syscom):
     *   Cada método llama a su endpoint específico de forma independiente.
     *   = 3 llamadas HTTP, una por tipo.
     */
    public function syncTodo(string $proveedorClave, array $filtros = []): array
    {
        $servicio      = $this->resolver($proveedorClave);
        $proveedorIdBd = $this->resolverProveedorIdBd($proveedorClave);

        if ($servicio->soportaConsultaUnificada()) {
            Log::info("[Orchestrator] syncTodo UNIFICADO {$proveedorClave} — 1 recorrido HTTP");

            // Una sola llamada al proveedor (pagina internamente si es necesario)
            $productos = $servicio->obtenerProductosParaActualizacion($filtros);

            // La misma colección se pasa a los tres métodos de persistencia
            // ProductoPersistenceService la chunkea en lotes de 500 internamente
            return [
                'precios'     => array_merge(
                    $this->persistencia->actualizarPrecios($productos, $proveedorIdBd),
                    ['proveedor' => $proveedorClave]
                ),
                'stock'       => array_merge(
                    $this->persistencia->actualizarStock($productos, $proveedorIdBd),
                    ['proveedor' => $proveedorClave]
                ),
                'promociones' => array_merge(
                    $this->persistencia->actualizarPromociones($productos, $proveedorIdBd),
                    ['proveedor' => $proveedorClave]
                ),
            ];
        }

        Log::info("[Orchestrator] syncTodo SEPARADO {$proveedorClave} — 3 llamadas HTTP");

        // Cada método llama a su endpoint específico del proveedor
        return [
            'precios'     => $this->syncPrecios($proveedorClave, $filtros),
            'stock'       => $this->syncStock($proveedorClave, $filtros),
            'promociones' => $this->syncPromociones($proveedorClave, $filtros),
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
                'exito'  => false,
                'error'  => "Artículo '{$idExterno}' no encontrado en proveedor '{$proveedorClave}'",
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