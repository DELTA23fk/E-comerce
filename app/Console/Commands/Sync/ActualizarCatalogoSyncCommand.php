<?php

namespace App\Console\Commands\Sync;

use App\Services\Sync\ProductoSyncOrchestrator;
use Illuminate\Console\Command;

/**
 * Actualización síncrona de catálogo sin uso de Jobs ni Queue.
 *
 * NOTA SOBRE STOCK:
 * tipo=stock actualiza AMBAS tablas en la misma pasada:
 *   - proveedor_productos.stock / stock_cd  (resumen, compatibilidad)
 *   - almacen_producto_stock               (detalle por almacén)
 * Son la misma información — nunca se actualizan por separado.
 *
 * IMPORTANTE SOBRE EL MODO 'todo':
 * Delega a orquestador->syncTodo() que internamente decide la estrategia:
 *   Proveedor UNIFICADO (ej. CVA) → 1 HTTP por página, todos los tipos por ciclo
 *   Proveedor SEPARADO (ej. Exel) → loops de paginación independientes
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
                            {--proveedor=  : Clave del proveedor (cva, exel...). Requerido}
                            {--tipo=todo   : Qué actualizar: precios|stock|promociones|todo}';

    protected $description = 'Actualiza precios, stock y/o promociones de un proveedor de forma síncrona (sin jobs)';

    // stock implica almacen_producto_stock también
    private const TIPOS_VALIDOS = ['precios', 'stock', 'promociones', 'todo'];

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

        if (!in_array($tipo, self::TIPOS_VALIDOS, true)) {
            $this->error("Tipo '{$tipo}' no válido. Opciones: " . implode(', ', self::TIPOS_VALIDOS));
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
            ? ($esUnificado ? '1 HTTP por página (unificado)' : 'loops independientes (separado)')
            : '1 loop de paginación';

        $notaStock = $tipo === 'stock'
            ? '(resumen proveedor_productos + detalle almacen_producto_stock)'
            : '';

        $this->newLine();
        $this->info("╔═══════════════════════════════════════════════════════════╗");
        $this->info("║         ACTUALIZACIÓN SÍNCRONA DE CATÁLOGO               ║");
        $this->info("╚═══════════════════════════════════════════════════════════╝");
        $this->newLine();
        $this->line("  🔌 Proveedor : " . strtoupper($clave));
        $this->line("  🔄 Tipo      : " . strtoupper($tipo) . ($notaStock ? " {$notaStock}" : ''));
        $this->line("  🌐 HTTP      : {$modoHttp}");
        $this->line("  ⚙️  Modo      : Síncrono (proceso actual, paginación externa)");
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

            $metricas = collect($datos)
                ->except(['duracion_s', 'proveedor', 'paginas'])
                ->map(fn($v, $k) => [$k, $v])
                ->values()
                ->toArray();

            $this->table(['Métrica', 'Valor'], $metricas);

            if (isset($datos['paginas'])) {
                $this->line("  📄 Páginas procesadas : {$datos['paginas']}");
            }
            if (isset($datos['duracion_s'])) {
                $this->line("  ⏱️  Tiempo            : {$datos['duracion_s']}s");
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

        $stats               = $orquestador->syncPrecios($clave);
        $stats['duracion_s'] = round(microtime(true) - $inicio, 2);

        $this->mostrarStatsLinea($stats);

        return ['precios' => $stats];
    }

    private function ejecutarStock(ProductoSyncOrchestrator $orquestador, string $clave): array
    {
        $this->info("  ▶ Actualizando stock (resumen + detalle por almacén)...");
        $inicio = microtime(true);

        // syncStock devuelve ['stock' => [...], 'almacenes' => [...]]
        $resultado     = $orquestador->syncStock($clave);
        $duracion      = round(microtime(true) - $inicio, 2);

        foreach ($resultado as &$datos) {
            $datos['duracion_s'] = $duracion;
        }
        unset($datos);

        $this->mostrarStatsLinea(array_merge($resultado['stock'], ['tipo' => 'stock']));
        $this->mostrarStatsLinea(array_merge($resultado['almacenes'], ['tipo' => 'almacenes']));

        return $resultado;
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
     * Delega a syncTodo() — la estrategia HTTP la decide el orquestador.
     * Devuelve precios + stock + almacenes + promociones.
     */
    private function ejecutarTodo(ProductoSyncOrchestrator $orquestador, string $clave): array
    {
        $this->info("  ▶ Actualizando todo (precios + stock + almacenes + promociones)...");
        $inicio = microtime(true);

        $resultado     = $orquestador->syncTodo($clave);
        $duracionTotal = round(microtime(true) - $inicio, 2);

        foreach ($resultado as $tipo => &$datos) {
            $datos['duracion_s'] = '-';
            $this->mostrarStatsLinea(array_merge($datos, ['tipo' => $tipo]));
        }
        unset($datos);

        $this->line("  ⏱️  Total: {$duracionTotal}s");

        return $resultado;
    }

    // =========================================================================
    // OUTPUT
    // =========================================================================

    private function mostrarStatsLinea(array $stats): void
    {
        $partes = collect($stats)
            ->except(['duracion_s', 'proveedor', 'paginas', 'tipo'])
            ->map(fn($v, $k) => "{$k}: {$v}")
            ->implode(' | ');

        $paginas  = isset($stats['paginas'])    ? " | páginas: {$stats['paginas']}" : '';
        $tipo     = isset($stats['tipo'])       ? "[{$stats['tipo']}] "             : '';
        $duracion = $stats['duracion_s'] ?? '-';

        $this->line("    {$tipo}{$partes}{$paginas} | ⏱️ {$duracion}s");
    }
}