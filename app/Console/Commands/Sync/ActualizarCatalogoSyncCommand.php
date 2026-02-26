<?php

namespace App\Console\Commands\Sync;

use App\Services\Sync\ProductoSyncOrchestrator;
use Illuminate\Console\Command;

/**
 * Actualización síncrona de catálogo sin uso de Jobs ni Queue.
 *
 * IMPORTANTE SOBRE EL MODO 'todo':
 * Este comando delega directamente a orquestador->syncTodo() que internamente
 * decide si usar 1 llamada HTTP (proveedor con consulta unificada, ej. CVA)
 * o 3 llamadas separadas (proveedor con endpoints distintos, ej. Exel).
 * El comando no necesita saber cuál estrategia se usa.
 *
 * Uso:
 *   php artisan sync:actualizar-sync --proveedor=cva
 *   php artisan sync:actualizar-sync --proveedor=cva --tipo=stock
 *   php artisan sync:actualizar-sync --proveedor=cva --tipo=precios
 *   php artisan sync:actualizar-sync --proveedor=cva --tipo=promociones
 *   php artisan sync:actualizar-sync --proveedor=cva --tipo=todo
 */
class ActualizarCatalogoSyncCommand extends Command
{
    protected $signature = 'sync:actualizar-sync
                            {--proveedor=       : Clave del proveedor (cva, exel...). Requerido}
                            {--tipo=todo        : Qué actualizar: precios|stock|promociones|todo}';

    protected $description = 'Actualiza precios, stock y/o promociones de un proveedor de forma síncrona (sin jobs)';

    public function handle(ProductoSyncOrchestrator $orquestador): int
    {
        $clave = $this->option('proveedor');
        $tipo  = $this->option('tipo');

        // ── Validaciones ──────────────────────────────────────────────────────

        if (!$clave) {
            $this->error('Debes especificar un proveedor con --proveedor=cva');
            $this->line('Proveedores disponibles: ' . implode(', ', $orquestador->proveedoresRegistrados()));
            return Command::FAILURE;
        }

        $tiposValidos = ['precios', 'stock', 'promociones', 'todo'];
        if (!in_array($tipo, $tiposValidos, true)) {
            $this->error("Tipo '{$tipo}' no válido. Opciones: " . implode(', ', $tiposValidos));
            return Command::FAILURE;
        }

        if (!in_array($clave, $orquestador->proveedoresRegistrados(), true)) {
            $this->error("Proveedor '{$clave}' no registrado.");
            $this->line('Disponibles: ' . implode(', ', $orquestador->proveedoresRegistrados()));
            return Command::FAILURE;
        }

        // ── Encabezado ────────────────────────────────────────────────────────

        $esUnificado = $orquestador->proveedorSoportaConsultaUnificada($clave);
        $modoHttp    = $tipo === 'todo'
            ? ($esUnificado ? '1 recorrido HTTP (unificado) por pagina' : '3 llamadas HTTP (separadas) por pagina')
            : '1 llamada HTTP';

        $this->newLine();
        $this->info("╔═══════════════════════════════════════════════════════════╗");
        $this->info("║         ACTUALIZACIÓN SÍNCRONA DE CATÁLOGO               ║");
        $this->info("╚═══════════════════════════════════════════════════════════╝");
        $this->newLine();
        $this->line("  🔌 Proveedor : " . strtoupper($clave));
        $this->line("  🔄 Tipo      : " . strtoupper($tipo));
        $this->line("  🌐 HTTP      : {$modoHttp}");
        $this->line("  ⚙️  Modo      : Síncrono (proceso actual)");

        if (!empty($filtros)) {
            $this->line("  📋 Filtros   : " . collect($filtros)->map(fn($v, $k) => "{$k}={$v}")->implode(', '));
        }

        $this->newLine();

        // ── Ejecución ─────────────────────────────────────────────────────────

        $tiempoTotal = microtime(true);

        $stats = match ($tipo) {
            'precios'     => $this->ejecutarPrecios($orquestador, $clave),
            'stock'       => $this->ejecutarStock($orquestador, $clave),
            'promociones' => $this->ejecutarPromociones($orquestador, $clave),
            'todo'        => $this->ejecutarTodo($orquestador, $clave),
        };

        $duracionTotal = round(microtime(true) - $tiempoTotal, 2);

        // ── Resumen ───────────────────────────────────────────────────────────

        $this->newLine();
        $this->info("╔═══════════════════════════════════════════════════════════╗");
        $this->info("║                    RESUMEN FINAL                         ║");
        $this->info("╚═══════════════════════════════════════════════════════════╝");
        $this->newLine();

        foreach ($stats as $tipoStats => $datos) {
            $this->info("  📊 " . strtoupper($tipoStats));
            $filas = collect($datos)
                ->except(['duracion_s', 'proveedor'])
                ->map(fn($v, $k) => [$k, $v])
                ->values()
                ->toArray();
            $this->table(['Métrica', 'Valor'], $filas);
            if (isset($datos['duracion_s'])) {
                $this->line("  ⏱️  Tiempo: {$datos['duracion_s']}s");
            }
            $this->newLine();
        }

        $this->info("  ✅ Completado en {$duracionTotal}s totales");
        $this->newLine();

        return Command::SUCCESS;
    }

    // =========================================================================
    // EJECUCIÓN POR TIPO
    // =========================================================================

    private function ejecutarPrecios(ProductoSyncOrchestrator $orquestador, string $clave): array
    {
        $this->info("  ▶ Actualizando precios...");
        $inicio = microtime(true);

        // El orquestador decide internamente si usar consulta unificada o endpoint
        // específico según soportaConsultaUnificada() del proveedor
        $stats               = $orquestador->syncPrecios($clave);
        $stats['duracion_s'] = round(microtime(true) - $inicio, 2);

        $this->mostrarStatsLinea($stats);

        return ['precios' => $stats];
    }

    private function ejecutarStock(ProductoSyncOrchestrator $orquestador, string $clave): array
    {
        $this->info("  ▶ Actualizando stock...");
        $inicio = microtime(true);

        $stats               = $orquestador->syncStock($clave);
        $stats['duracion_s'] = round(microtime(true) - $inicio, 2);

        $this->mostrarStatsLinea($stats);

        return ['stock' => $stats];
    }

    private function ejecutarPromociones(ProductoSyncOrchestrator $orquestador, string $clave): array
    {
        $this->info("  ▶ Actualizando promociones...");
        $inicio = microtime(true);

        $stats               = $orquestador->syncPromociones($clave);
        $stats['duracion_s'] = round(microtime(true) - $inicio, 2);

        $this->mostrarStatsLinea($stats);

        return ['promociones' => $stats];
    }

    /**
     * Delega a syncTodo() que internamente decide la estrategia:
     *
     * Proveedor UNIFICADO (ej. CVA):
     *   → obtenerProductosParaActualizacion() — 1 recorrido HTTP
     *   → actualizarPrecios() + actualizarStock() + actualizarPromociones()
     *     con la misma colección
     *
     * Proveedor SEPARADO (ej. Exel):
     *   → obtenerProductosConPrecioActualizado() — 1 HTTP
     *   → obtenerProductosConStockActualizado()  — 1 HTTP
     *   → obtenerProductosEnPromocion()           — 1 HTTP
     */
    private function ejecutarTodo(ProductoSyncOrchestrator $orquestador, string $clave): array
    {
        $this->info("  ▶ Actualizando todo (precio + stock + promociones)...");
        $inicio = microtime(true);

        // syncTodo maneja la optimización internamente
        $resultado = $orquestador->syncTodo($clave);

        $duracionTotal = round(microtime(true) - $inicio, 2);

        // Agregar duración individual a cada sección para el resumen
        foreach ($resultado as $tipo => &$stats) {
            $stats['duracion_s'] = $stats['duracion_s'] ?? '-';
            $this->mostrarStatsLinea(array_merge($stats, ['tipo' => $tipo]));
        }

        $this->line("  ⏱️  Total: {$duracionTotal}s");

        return $resultado;
    }

    // =========================================================================
    // OUTPUT
    // =========================================================================

    private function mostrarStatsLinea(array $stats): void
    {
        $partes = collect($stats)
            ->except(['duracion_s', 'proveedor', 'tipo'])
            ->map(fn($v, $k) => "{$k}: {$v}")
            ->implode(' | ');

        $duracion = $stats['duracion_s'] ?? '-';
        $this->line("    {$partes} | ⏱️ {$duracion}s");
    }
}