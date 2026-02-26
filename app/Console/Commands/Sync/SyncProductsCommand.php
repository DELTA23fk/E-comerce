<?php

namespace App\Console\Commands\Sync;

use App\Services\Sync\ProductoSyncOrchestrator;
use Illuminate\Console\Command;

/**
 * Comando de Sincronización Inicial de Productos
 *
 * Realiza la primera carga completa desde cualquier proveedor registrado.
 * La paginación es EXTERNA: este comando controla página por página y
 * el orquestador persiste cada una antes de pasar a la siguiente.
 *
 * Uso:
 *   php artisan sync:productos --proveedor=cva --all
 *   php artisan sync:productos --proveedor=cva --page=3
 *   php artisan sync:productos --all   ← todos los proveedores
 */
class SyncProductsCommand extends Command
{
    protected $signature = 'sync:productos
                            {--proveedor=      : Clave del proveedor (cva, exel...). Sin valor = todos}
                            {--page=1          : Página inicial}
                            {--all             : Procesar todas las páginas sin pausas}';

    protected $description = 'Sincronización inicial completa de productos desde uno o todos los proveedores';

    public function handle(ProductoSyncOrchestrator $orquestador): int
    {
        $claveProveedor = $this->option('proveedor');
        $modoAuto       = $this->option('all');
        $paginaInicial  = (int) $this->option('page');

        $proveedores = $claveProveedor
            ? [$claveProveedor]
            : $orquestador->proveedoresRegistrados();

        if (empty($proveedores)) {
            $this->error('No hay proveedores registrados en el orquestador.');
            return Command::FAILURE;
        }

        // Validar claves antes de empezar
        foreach ($proveedores as $clave) {
            if (!in_array($clave, $orquestador->proveedoresRegistrados(), true)) {
                $this->error("Proveedor '{$clave}' no registrado.");
                $this->line('Disponibles: ' . implode(', ', $orquestador->proveedoresRegistrados()));
                return Command::FAILURE;
            }
        }

        $this->mostrarEncabezado($proveedores, $paginaInicial, $modoAuto);

        $resumenGeneral = [];

        foreach ($proveedores as $clave) {
            $this->newLine();
            $this->info("┌─────────────────────────────────────────────────────────┐");
            $this->info("│  Proveedor: " . strtoupper($clave) . str_repeat(' ', max(0, 45 - strlen($clave))) . "│");
            $this->info("└─────────────────────────────────────────────────────────┘");

            $resumenGeneral[$clave] = $this->sincronizarProveedor(
                $orquestador, $clave, $paginaInicial, $modoAuto
            );
        }

        $this->mostrarResumenFinal($resumenGeneral);

        return Command::SUCCESS;
    }

    // =========================================================================
    // SINCRONIZACIÓN POR PROVEEDOR
    // =========================================================================

    /**
     * Itera página por página llamando a syncInicial().
     * La paginación es controlada aquí — el orquestador solo persiste
     * una página a la vez, sin cargar todo el catálogo en memoria.
     */
    private function sincronizarProveedor(
        ProductoSyncOrchestrator $orquestador,
        string $clave,
        int $paginaInicial,
        bool $modoAuto
    ): array {
        $paginaActual             = $paginaInicial;
        $totalPaginas             = null;
        $paginasProcesadas        = 0;
        $totalProductosProcesados = 0;
        $infoPaginacion           = null;

        do {
            $this->mostrarEncabezadoPagina($paginaActual, $totalPaginas, $clave);

            $inicio = microtime(true);

            try {
                // syncInicial → 1 llamada HTTP → persistirBatch() → retorna metadatos
                $infoPaginacion = $orquestador->syncInicial(proveedorClave:$clave, pagina:$paginaActual);
                $duracion       = round(microtime(true) - $inicio, 2);

                if (!$infoPaginacion) {
                    $this->warn("  ⚠️  Sin datos en página {$paginaActual} para {$clave}.");
                    break;
                }

                $totalPaginas              = $infoPaginacion['total_paginas'];
                $productosPagina           = $infoPaginacion['productos_procesados'];
                $totalProductosProcesados += $productosPagina;
                $paginasProcesadas++;

                $this->mostrarResultadosPagina($paginaActual, $productosPagina, $duracion);
                $this->mostrarProgreso($paginaActual, $totalPaginas, $totalProductosProcesados);

                // Modo interactivo: preguntar si continuar
                if (!$modoAuto && $infoPaginacion['hay_mas']) {
                    if (!$this->confirm("  ¿Continuar con página " . ($paginaActual + 1) . " de {$totalPaginas}?", true)) {
                        $this->warn("  ⏸️  Sincronización pausada en página {$paginaActual}.");
                        $this->comment("  Para continuar: php artisan sync:productos --proveedor={$clave} --page=" . ($paginaActual + 1));
                        break;
                    }
                }

                $paginaActual++;

            } catch (\Throwable $e) {
                $this->error("  ❌ Error en página {$paginaActual}: " . $e->getMessage());

                if ($this->confirm('  ¿Reintentar esta página?', true)) {
                    continue;
                }

                break;
            }

        } while ($infoPaginacion && $infoPaginacion['hay_mas']);

        return [
            'paginas_procesadas' => $paginasProcesadas,
            'total_productos'    => $totalProductosProcesados,
        ];
    }

    // =========================================================================
    // OUTPUT
    // =========================================================================

    private function mostrarEncabezado(array $proveedores, int $paginaInicial, bool $modoAuto): void
    {
        $this->newLine();
        $this->info("╔═══════════════════════════════════════════════════════════╗");
        $this->info("║         SINCRONIZACIÓN INICIAL DE PRODUCTOS               ║");
        $this->info("╚═══════════════════════════════════════════════════════════╝");
        $this->newLine();
        $this->line("  🔌 Proveedores : " . implode(', ', array_map('strtoupper', $proveedores)));
        $this->line("  📄 Desde página: {$paginaInicial}");
        $this->line("  ⚙️  Modo        : " . ($modoAuto ? 'Automático (--all)' : 'Interactivo'));
        $this->newLine();
    }

    private function mostrarEncabezadoPagina(int $pagina, ?int $total, string $clave): void
    {
        $info = "  📄 [{$clave}] Procesando página {$pagina}" . ($total ? " de {$total}" : '');
        $this->line(str_repeat('─', 60));
        $this->line($info);
        $this->line(str_repeat('─', 60));
    }

    private function mostrarResultadosPagina(int $pagina, int $productos, float $duracion): void
    {
        $this->info("  ✅ Página {$pagina} persistida — {$productos} productos en {$duracion}s");
    }

    private function mostrarProgreso(int $actual, int $total, int $productosTotal): void
    {
        if ($total <= 0) return;

        $porcentaje = round(($actual / $total) * 100, 1);
        $llena      = (int) ($porcentaje / 2);
        $barra      = str_repeat('█', $llena) . str_repeat('░', 50 - $llena);

        $this->line("  📊 [{$barra}] {$porcentaje}% — {$productosTotal} productos acumulados");
        $this->newLine();
    }

    private function mostrarResumenFinal(array $resumen): void
    {
        $this->newLine();
        $this->info("╔═══════════════════════════════════════════════════════════╗");
        $this->info("║              RESUMEN FINAL DE SINCRONIZACIÓN              ║");
        $this->info("╚═══════════════════════════════════════════════════════════╝");
        $this->newLine();

        $totalProductos = 0;
        $totalPaginas   = 0;
        $filas          = [];

        foreach ($resumen as $clave => $datos) {
            $totalProductos += $datos['total_productos'];
            $totalPaginas   += $datos['paginas_procesadas'];
            $filas[]         = [strtoupper($clave), $datos['paginas_procesadas'], $datos['total_productos']];
        }

        $this->table(['Proveedor', 'Páginas', 'Productos'], $filas);
        $this->newLine();
        $this->line("  📄 Total páginas  : {$totalPaginas}");
        $this->line("  📦 Total productos: {$totalProductos}");
        $this->newLine();
        $this->info("  🎉 Sincronización inicial completada.");
        $this->newLine();
        $this->comment("  💡 Para mantener actualizado: php artisan sync:actualizar-sync --proveedor=<clave>");
        $this->newLine();
    }
}