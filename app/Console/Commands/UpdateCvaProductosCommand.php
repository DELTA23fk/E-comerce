<?php

namespace App\Console\Commands;

use App\Services\ProductoSyncService;
use Illuminate\Console\Command;
use Spatie\LaravelData\DataCollection;

/**
 * Comando de Actualización de Productos CVA
 * 
 * Actualiza productos existentes con los últimos datos de CVA.
 * Permite actualizaciones selectivas:
 * - Solo precios (--type=prices)
 * - Solo stock (--type=stock)
 * - Solo promociones (--type=promotions)
 * - Todo junto (--type=all)
 * 
 * Uso:
 * php artisan update:cva-products --type=prices --all        # Actualizar precios de todas las páginas
 * php artisan update:cva-products --type=stock --page=3      # Stock desde página 3
 * php artisan update:cva-products --type=all                 # Todo con paginación interactiva
 */
class UpdateCvaProductosCommand extends Command
{    
    protected $signature = 'update:cva-products 
                            {--type=all : Tipo de actualización (prices|stock|promotions|all)}
                            {--upc=true : Incluir productos con UPC}
                            {--promos=true : Incluir promociones}
                            {--MonedaPesos=true : Solo productos en pesos mexicanos}
                            {--completos=1 : Solo productos con datos completos}
                            {--exist=2 : Nivel de existencia (1=con stock, 2=todos)}
                            {--page=1 : Página inicial}
                            {--all : Actualizar todas las páginas automáticamente}';

    protected $description = 'Actualiza precios, stock y/o promociones de productos CVA existentes';

    public function handle(ProductoSyncService $syncService)
    {
        $proveedorIdBd = 1; // ID del proveedor CVA en base de datos
        
        // Construir filtros
        $filtros = array_filter([
            'upc' => $this->option('upc'),
            'exist' => $this->option('exist'),
            'completos' => $this->option('completos'),
            'MonedaPesos' => $this->option('MonedaPesos'),
            'promos' => $this->option('promos')
        ]);

        $tipoActualizacion = strtolower($this->option('type'));
        $paginaInicial = (int) $this->option('page');
        $actualizarTodo = $this->option('all');

        // Validar tipo de actualización
        $tiposValidos = ['prices', 'stock', 'promotions', 'all'];
        if (!in_array($tipoActualizacion, $tiposValidos)) {
            $this->error("❌ Tipo de actualización inválido. Use: " . implode(', ', $tiposValidos));
            return Command::FAILURE;
        }

        // Mostrar encabezado
        $this->mostrarEncabezado($tipoActualizacion, $filtros);

        $paginaActual = $paginaInicial;
        $totalPaginas = null;
        $estadisticasGlobales = $this->inicializarEstadisticasGlobales($tipoActualizacion);

        // Loop principal de actualización
        do {
            $this->mostrarEncabezadoPagina($paginaActual, $totalPaginas);
            
            $tiempoInicio = microtime(true);
            
            try {
                // Obtener datos de la API
                $respuesta = $syncService->obtenerProductosGenerales($filtros, $paginaActual);
                
                if ($respuesta->articulos->count() === 0) {
                    $this->info("✅ No hay más productos para procesar");
                    break;
                }
                
                // Ejecutar actualización según el tipo
                $estadisticasPagina = $this->ejecutarActualizacion(
                    $syncService, 
                    $respuesta->articulos, 
                    $proveedorIdBd, 
                    $tipoActualizacion
                );
                
                // Acumular estadísticas
                $this->combinarEstadisticas($estadisticasGlobales, $estadisticasPagina);
                
                $duracion = round(microtime(true) - $tiempoInicio, 2);
                
                // Mostrar resultados de la página
                $this->mostrarResultadosPagina($paginaActual, $estadisticasPagina, $duracion);
                
                // Actualizar información de paginación
                if ($respuesta->paginacion) {
                    $totalPaginas = $respuesta->paginacion->totalPaginas;
                    $this->mostrarProgreso($paginaActual, $totalPaginas);
                }
                
                // Preguntar si continuar (solo si no es --all)
                if (!$actualizarTodo && $paginaActual < $totalPaginas) {
                    $siguientePagina = $paginaActual + 1;
                    
                    if (!$this->confirm("¿Continuar con la página {$siguientePagina} de {$totalPaginas}?", true)) {
                        $this->warn("⏸️  Actualización pausada por el usuario.");
                        break;
                    }
                }
                
                $paginaActual++;
                
            } catch (\Exception $e) {
                $this->error("❌ Error en página {$paginaActual}: " . $e->getMessage());
                $this->error($e->getTraceAsString());
                
                if ($this->confirm('¿Reintentar esta página?', true)) {
                    continue;
                }
                
                break;
            }
            
        } while ($paginaActual <= $totalPaginas || ($actualizarTodo && $respuesta->articulos->count() > 0));

        // Mostrar resumen final
        $this->mostrarResumenFinal($estadisticasGlobales, $tipoActualizacion);
        
        return Command::SUCCESS;
    }

    /**
     * Ejecuta la actualización según el tipo seleccionado
     */
    protected function ejecutarActualizacion(
        ProductoSyncService $syncService, 
        DataCollection $articulos, 
        int $proveedorIdBd, 
        string $tipoActualizacion
    ): array {
        return match($tipoActualizacion) {
            'prices' => $this->actualizarPrecios($syncService, $articulos, $proveedorIdBd),
            'stock' => $this->actualizarStock($syncService, $articulos, $proveedorIdBd),
            'promotions' => $this->actualizarPromociones($syncService, $articulos, $proveedorIdBd),
            'all' => $this->actualizarTodo($syncService, $articulos, $proveedorIdBd),
        };
    }

    /**
     * Actualiza solo precios
     */
    protected function actualizarPrecios(
        ProductoSyncService $syncService, 
        DataCollection $articulos, 
        int $proveedorIdBd
    ): array {
        $this->line("💰 Actualizando precios...");
        
        return $syncService->actualizarPreciosBatch($articulos, $proveedorIdBd);
    }

    /**
     * Actualiza solo stock
     */
    protected function actualizarStock(
        ProductoSyncService $syncService, 
        DataCollection $articulos, 
        int $proveedorIdBd
    ): array {
        $this->line("📦 Actualizando stock...");
        
        return $syncService->actualizarStockBatch($articulos, $proveedorIdBd);
    }

    /**
     * Actualiza solo promociones
     */
    protected function actualizarPromociones(
        ProductoSyncService $syncService, 
        DataCollection $articulos, 
        int $proveedorIdBd
    ): array {
        $this->line("🎁 Actualizando promociones...");
        
        return $syncService->actualizarPromocionesBatch($articulos, $proveedorIdBd);
    }

    /**
     * Actualiza todo (precios, stock, promociones)
     */
    protected function actualizarTodo(
        ProductoSyncService $syncService, 
        DataCollection $articulos, 
        int $proveedorIdBd
    ): array {
        $this->line("🔄 Actualizando precios, stock y promociones...");
        
        $estadisticasPrecios = $syncService->actualizarPreciosBatch($articulos, $proveedorIdBd);
        $estadisticasStock = $syncService->actualizarStockBatch($articulos, $proveedorIdBd);
        $estadisticasPromociones = $syncService->actualizarPromocionesBatch($articulos, $proveedorIdBd);
        
        return [
            'precios' => $estadisticasPrecios,
            'stock' => $estadisticasStock,
            'promociones' => $estadisticasPromociones,
        ];
    }

    /**
     * Inicializa estadísticas globales según el tipo de actualización
     */
    protected function inicializarEstadisticasGlobales(string $tipoActualizacion): array
    {
        $estadisticasBase = [
            'total' => 0, 
            'actualizados' => 0, 
            'sin_cambios' => 0, 
            'errores' => 0
        ];
        
        if ($tipoActualizacion === 'all') {
            return [
                'precios' => $estadisticasBase,
                'stock' => $estadisticasBase,
                'promociones' => [
                    'total' => 0, 
                    'creadas' => 0, 
                    'stock_actualizado' => 0,
                    'sin_cambios' => 0, 
                    'expiradas' => 0, 
                    'errores' => 0
                ],
            ];
        }
        
        if ($tipoActualizacion === 'promotions') {
            return [
                'total' => 0, 
                'creadas' => 0, 
                'stock_actualizado' => 0,
                'sin_cambios' => 0, 
                'expiradas' => 0, 
                'errores' => 0
            ];
        }
        
        return $estadisticasBase;
    }

    /**
     * Combina estadísticas de página con globales
     */
    protected function combinarEstadisticas(array &$estadisticasGlobales, array $estadisticasPagina): void
    {
        if (isset($estadisticasPagina['precios'])) {
            // Actualización completa (all)
            foreach (['precios', 'stock', 'promociones'] as $tipo) {
                if (isset($estadisticasPagina[$tipo])) {
                    foreach ($estadisticasPagina[$tipo] as $clave => $valor) {
                        $estadisticasGlobales[$tipo][$clave] = ($estadisticasGlobales[$tipo][$clave] ?? 0) + $valor;
                    }
                }
            }
        } else {
            // Actualización simple
            foreach ($estadisticasPagina as $clave => $valor) {
                $estadisticasGlobales[$clave] = ($estadisticasGlobales[$clave] ?? 0) + $valor;
            }
        }
    }

    /**
     * Muestra encabezado del comando
     */
    protected function mostrarEncabezado(string $tipoActualizacion, array $filtros): void
    {
        $this->newLine();
        $this->info("╔═══════════════════════════════════════════════════════════╗");
        $this->info("║        ACTUALIZACIÓN DE PRODUCTOS CVA                     ║");
        $this->info("╚═══════════════════════════════════════════════════════════╝");
        $this->newLine();
        
        $nombresActualizacion = [
            'prices' => '💰 Precios',
            'stock' => '📦 Stock',
            'promotions' => '🎁 Promociones',
            'all' => '🔄 Completa (Precios, Stock y Promociones)',
        ];
        
        $this->info("Tipo de actualización: " . ($nombresActualizacion[$tipoActualizacion] ?? $tipoActualizacion));
        
        if (!empty($filtros)) {
            $this->newLine();
            $this->info("📋 Filtros aplicados:");
            foreach ($filtros as $clave => $valor) {
                $this->line("  • {$clave}: {$valor}");
            }
        }
        
        $this->newLine();
    }

    /**
     * Muestra encabezado de página
     */
    protected function mostrarEncabezadoPagina(int $paginaActual, ?int $totalPaginas): void
    {
        $infoPagina = "📄 Procesando página {$paginaActual}";
        if ($totalPaginas) {
            $infoPagina .= " de {$totalPaginas}";
        }
        
        $this->info(str_repeat('─', 60));
        $this->info($infoPagina);
        $this->info(str_repeat('─', 60));
    }

    /**
     * Muestra resultados de la página procesada
     */
    protected function mostrarResultadosPagina(int $pagina, array $estadisticas, float $duracion): void
    {
        $this->newLine();
        $this->info("✅ Página {$pagina} completada en {$duracion}s");
        
        if (isset($estadisticas['precios'])) {
            // Actualización completa
            $this->line("  💰 Precios:");
            $this->mostrarEstadisticasSimples($estadisticas['precios'], '    ');
            
            $this->line("  📦 Stock:");
            $this->mostrarEstadisticasSimples($estadisticas['stock'], '    ');
            
            $this->line("  🎁 Promociones:");
            $this->mostrarEstadisticasPromociones($estadisticas['promociones'], '    ');
        } elseif (isset($estadisticas['creadas'])) {
            // Solo promociones
            $this->mostrarEstadisticasPromociones($estadisticas);
        } else {
            // Precios o stock
            $this->mostrarEstadisticasSimples($estadisticas);
        }
        
        $this->newLine();
    }

    /**
     * Muestra estadísticas simples (precios o stock)
     */
    protected function mostrarEstadisticasSimples(array $estadisticas, string $indentacion = '  '): void
    {
        $this->line($indentacion . "Total procesados: {$estadisticas['total']}");
        $this->line($indentacion . "✓ Actualizados: {$estadisticas['actualizados']}");
        $this->line($indentacion . "○ Sin cambios: {$estadisticas['sin_cambios']}");
        
        if ($estadisticas['errores'] > 0) {
            $this->warn($indentacion . "✗ Errores: {$estadisticas['errores']}");
        }
    }

    /**
     * Muestra estadísticas de promociones
     */
    protected function mostrarEstadisticasPromociones(array $estadisticas, string $indentacion = '  '): void
    {
        $this->line($indentacion . "Total procesados: {$estadisticas['total']}");
        $this->line($indentacion . "✓ Creadas: {$estadisticas['creadas']}");
        $this->line($indentacion . "↻ Stock actualizado: {$estadisticas['stock_actualizado']}");
        $this->line($indentacion . "○ Sin cambios: {$estadisticas['sin_cambios']}");
        $this->line($indentacion . "⏱ Expiradas: {$estadisticas['expiradas']}");
        
        if ($estadisticas['errores'] > 0) {
            $this->warn($indentacion . "✗ Errores: {$estadisticas['errores']}");
        }
    }

    /**
     * Muestra barra de progreso
     */
    protected function mostrarProgreso(int $actual, int $total): void
    {
        $porcentaje = round(($actual / $total) * 100, 1);
        $barraLlena = (int)($porcentaje / 2);
        $barraVacia = 50 - $barraLlena;
        $barraProgreso = str_repeat('█', $barraLlena) . str_repeat('░', $barraVacia);
        
        $this->line("📊 Progreso: [{$barraProgreso}] {$porcentaje}%");
    }

    /**
     * Muestra resumen final
     */
    protected function mostrarResumenFinal(array $estadisticasGlobales, string $tipoActualizacion): void
    {
        $this->newLine();
        $this->info("╔═══════════════════════════════════════════════════════════╗");
        $this->info("║          RESUMEN FINAL DE ACTUALIZACIÓN                   ║");
        $this->info("╚═══════════════════════════════════════════════════════════╝");
        $this->newLine();
        
        if ($tipoActualizacion === 'all') {
            $this->info("💰 PRECIOS:");
            $this->mostrarEstadisticasSimples($estadisticasGlobales['precios']);
            $this->newLine();
            
            $this->info("📦 STOCK:");
            $this->mostrarEstadisticasSimples($estadisticasGlobales['stock']);
            $this->newLine();
            
            $this->info("🎁 PROMOCIONES:");
            $this->mostrarEstadisticasPromociones($estadisticasGlobales['promociones']);
        } elseif ($tipoActualizacion === 'promotions') {
            $this->mostrarEstadisticasPromociones($estadisticasGlobales);
        } else {
            $this->mostrarEstadisticasSimples($estadisticasGlobales);
        }
        
        $this->newLine();
        $this->info("🎉 Actualización completada exitosamente");
        $this->newLine();
    }
}