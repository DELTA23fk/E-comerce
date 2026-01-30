<?php

namespace App\Console\Commands;

use App\Factories\ProductoFactory;
use App\Services\ProductoSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class UpdateCvaProductosCommand extends Command
{    
    protected $signature = 'update:cva-products 
                            {--type=all : Tipo de actualización (prices|stock|promotions|all)}
                            {--upc=true} 
                            {--promos=true} 
                            {--MonedaPesos=true} 
                            {--completos=1} 
                            {--exist=2}
                            {--page=1 : Página inicial}
                            {--all : Actualizar todas las páginas automáticamente}';

    protected $description = 'Actualiza precios, stock y/o promociones de productos CVA con paginación';

    public function handle(ProductoSyncService $syncService)
    {
        $providerIdDb = 1; // ID del proveedor CVA en tu DB
        
        $filters = array_filter([
            'upc' => $this->option('upc'),
            'exist' => $this->option('exist'),
            'completos' => $this->option('completos'),
            'MonedaPesos' => $this->option('MonedaPesos'),
            'promos' => $this->option('promos')
        ]);

        $updateType = strtolower($this->option('type'));
        $startPage = (int) $this->option('page');
        $syncAll = $this->option('all');

        // Validar tipo de actualización
        $validTypes = ['prices', 'stock', 'promotions', 'all'];
        if (!in_array($updateType, $validTypes)) {
            $this->error("❌ Tipo de actualización inválido. Use: " . implode(', ', $validTypes));
            return Command::FAILURE;
        }

        $this->displayHeader($updateType, $filters);

        $currentPage = $startPage;
        $totalPages = null;
        $globalStats = $this->initializeGlobalStats($updateType);

        do {
            $this->displayPageHeader($currentPage, $totalPages);
            
            $startTime = microtime(true);
            
            try {
                $productos = collect();
                // Obtener datos de la API
                $data = $syncService->getProductsGeneral($filters, $currentPage);

                
                if ($data->articulos->count() === 0) {
                    $this->info("✅ No hay más productos para procesar");
                    break;
                }          
                
                // Actualizar según el tipo seleccionado
                $pageStats = $this->executeUpdate($syncService, $productos->all(), $providerIdDb, $updateType);
                
                // Acumular estadísticas
                $this->mergeStats($globalStats, $pageStats);
                
                $duration = round(microtime(true) - $startTime, 2);
                
                // Mostrar resultados de la página
                $this->displayPageResults($currentPage, $pageStats, $duration);
                
                // Actualizar información de paginación
                if ($data->paginacion) {
                    $totalPages = $data->paginacion->totalPaginas;
                    $this->displayProgress($currentPage, $totalPages);
                }
                
                // Preguntar si continuar (solo si no es --all)
                if (!$syncAll && $currentPage < $totalPages) {
                    $nextPage = $currentPage + 1;
                    
                    if (!$this->confirm("¿Continuar con la página {$nextPage} de {$totalPages}?", true)) {
                        $this->warn("⏸️  Actualización pausada por el usuario.");
                        break;
                    }
                }
                
                $currentPage++;
                
            } catch (\Exception $e) {
                $this->error("❌ Error en página {$currentPage}: " . $e->getMessage());
                $this->error($e->getTraceAsString());
                
                if ($this->confirm('¿Reintentar esta página?', true)) {
                    continue;
                }
                
                break;
            }
            
        } while ($currentPage <= $totalPages || ($syncAll && $data->articulos->count() > 0));

        // Mostrar resumen final
        $this->displayFinalSummary($globalStats, $updateType);
        
        return Command::SUCCESS;
    }

    /**
     * Ejecuta la actualización según el tipo seleccionado
     */
    protected function executeUpdate(
        ProductoSyncService $syncService, 
        $articles, 
        int $providerIdDb, 
        string $updateType
    ): array {
        $stats = [];
        
        switch ($updateType) {
            case 'prices':
                $stats = $this->updatePricesForBatch($syncService, $articles, $providerIdDb);
                break;
                
            case 'stock':
                $stats = $this->updateStockForBatch($syncService, $articles, $providerIdDb);
                break;
                
            case 'promotions':
                $stats = $this->updatePromotionsForBatch($syncService, $articles, $providerIdDb);
                break;
                
            case 'all':
                $stats = $this->updateAllForBatch($syncService, $articles, $providerIdDb);
                break;
        }
        
        return $stats;
    }

    /**
     * Actualiza solo precios de un batch
     */
    protected function updatePricesForBatch(
        ProductoSyncService $syncService, 
        $articles, 
        int $providerIdDb
    ): array {
        $this->info("💰 Actualizando precios...");
        
        return $syncService->updatePricesBatch($articles, $providerIdDb);
    }

    /**
     * Actualiza solo stock de un batch
     */
    protected function updateStockForBatch(
        ProductoSyncService $syncService, 
        $articles, 
        int $providerIdDb
    ): array {
        $this->info("📦 Actualizando stock...");
        
        return $syncService->updateStockBatch($articles, $providerIdDb);
    }

    /**
     * Actualiza solo promociones de un batch
     */
    protected function updatePromotionsForBatch(
        ProductoSyncService $syncService, 
        $articles, 
        int $providerIdDb
    ): array {
        $this->info("🎁 Actualizando promociones...");
        
        return $syncService->updatePromotionsBatch($articles, $providerIdDb);
    }

    /**
     * Actualiza todo (precios, stock, promociones)
     */
    protected function updateAllForBatch(
        ProductoSyncService $syncService, 
        $articles, 
        int $providerIdDb
    ): array {
        $this->info("🔄 Actualizando precios, stock y promociones...");
        
        $pricesStats = $syncService->updatePricesBatch($articles, $providerIdDb);
        $stockStats = $syncService->updateStockBatch($articles, $providerIdDb);
        $promosStats = $syncService->updatePromotionsBatch($articles, $providerIdDb);
        
        return [
            'prices' => $pricesStats,
            'stock' => $stockStats,
            'promotions' => $promosStats,
        ];
    }

    /**
     * Inicializa estadísticas globales
     */
    protected function initializeGlobalStats(string $updateType): array
    {
        $baseStats = ['total' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => 0];
        
        if ($updateType === 'all') {
            return [
                'prices' => $baseStats,
                'stock' => $baseStats,
                'promotions' => ['total' => 0, 'created' => 0, 'unchanged' => 0, 'expired' => 0, 'errors' => 0],
            ];
        }
        
        if ($updateType === 'promotions') {
            return ['total' => 0, 'created' => 0, 'unchanged' => 0, 'expired' => 0, 'errors' => 0];
        }
        
        return $baseStats;
    }

    /**
     * Combina estadísticas de página con globales
     */
    protected function mergeStats(array &$globalStats, array $pageStats): void
    {
        if (isset($pageStats['prices'])) {
            // Es una actualización completa (all)
            foreach (['prices', 'stock', 'promotions'] as $type) {
                if (isset($pageStats[$type])) {
                    foreach ($pageStats[$type] as $key => $value) {
                        $globalStats[$type][$key] = ($globalStats[$type][$key] ?? 0) + $value;
                    }
                }
            }
        } else {
            // Es una actualización simple
            foreach ($pageStats as $key => $value) {
                $globalStats[$key] = ($globalStats[$key] ?? 0) + $value;
            }
        }
    }

    /**
     * Muestra encabezado del comando
     */
    protected function displayHeader(string $updateType, array $filters): void
    {
        $this->newLine();
        $this->info("╔═══════════════════════════════════════════════════════════╗");
        $this->info("║      ACTUALIZACIÓN DE PRODUCTOS CVA                       ║");
        $this->info("╚═══════════════════════════════════════════════════════════╝");
        $this->newLine();
        
        $typeNames = [
            'prices' => '💰 Precios',
            'stock' => '📦 Stock',
            'promotions' => '🎁 Promociones',
            'all' => '🔄 Todo (Precios, Stock y Promociones)',
        ];
        
        $this->info("Tipo de actualización: " . ($typeNames[$updateType] ?? $updateType));
        
        if (!empty($filters)) {
            $this->info("Filtros aplicados: " . json_encode($filters, JSON_PRETTY_PRINT));
        }
        
        $this->newLine();
    }

    /**
     * Muestra encabezado de página
     */
    protected function displayPageHeader(int $currentPage, ?int $totalPages): void
    {
        $pageInfo = "📄 Procesando página {$currentPage}";
        if ($totalPages) {
            $pageInfo .= " de {$totalPages}";
        }
        
        $this->info(str_repeat('─', 60));
        $this->info($pageInfo);
        $this->info(str_repeat('─', 60));
    }

    /**
     * Muestra resultados de la página
     */
    protected function displayPageResults(int $page, array $stats, float $duration): void
    {
        $this->newLine();
        $this->info("✅ Página {$page} completada en {$duration}s");
        
        if (isset($stats['prices'])) {
            // Actualización completa
            $this->line("  💰 Precios:");
            $this->displaySimpleStats($stats['prices'], '    ');
            
            $this->line("  📦 Stock:");
            $this->displaySimpleStats($stats['stock'], '    ');
            
            $this->line("  🎁 Promociones:");
            $this->displayPromotionStats($stats['promotions'], '    ');
        } elseif (isset($stats['created'])) {
            // Solo promociones
            $this->displayPromotionStats($stats);
        } else {
            // Precios o stock
            $this->displaySimpleStats($stats);
        }
        
        $this->newLine();
    }

    /**
     * Muestra estadísticas simples
     */
    protected function displaySimpleStats(array $stats, string $indent = '  '): void
    {
        $this->line($indent . "Total procesados: {$stats['total']}");
        $this->line($indent . "✓ Actualizados: {$stats['updated']}");
        $this->line($indent . "○ Sin cambios: {$stats['unchanged']}");
        
        if ($stats['errors'] > 0) {
            $this->warn($indent . "✗ Errores: {$stats['errors']}");
        }
    }

    /**
     * Muestra estadísticas de promociones
     */
    protected function displayPromotionStats(array $stats, string $indent = '  '): void
    {
        $this->line($indent . "Total procesados: {$stats['total']}");
        $this->line($indent . "✓ Creadas/Actualizadas: {$stats['created']}");
        $this->line($indent . "○ Sin cambios: {$stats['unchanged']}");
        $this->line($indent . "⏱ Expiradas: {$stats['expired']}");
        
        if ($stats['errors'] > 0) {
            $this->warn($indent . "✗ Errores: {$stats['errors']}");
        }
    }

    /**
     * Muestra progreso
     */
    protected function displayProgress(int $current, int $total): void
    {
        $progress = round(($current / $total) * 100, 1);
        $progressBar = str_repeat('█', (int)($progress / 2)) . str_repeat('░', 50 - (int)($progress / 2));
        
        $this->line("📊 Progreso: [{$progressBar}] {$progress}%");
    }

    /**
     * Muestra resumen final
     */
    protected function displayFinalSummary(array $globalStats, string $updateType): void
    {
        $this->newLine();
        $this->info("╔═══════════════════════════════════════════════════════════╗");
        $this->info("║              RESUMEN FINAL DE ACTUALIZACIÓN               ║");
        $this->info("╚═══════════════════════════════════════════════════════════╝");
        $this->newLine();
        
        if ($updateType === 'all') {
            $this->info("💰 PRECIOS:");
            $this->displaySimpleStats($globalStats['prices']);
            $this->newLine();
            
            $this->info("📦 STOCK:");
            $this->displaySimpleStats($globalStats['stock']);
            $this->newLine();
            
            $this->info("🎁 PROMOCIONES:");
            $this->displayPromotionStats($globalStats['promotions']);
        } elseif ($updateType === 'promotions') {
            $this->displayPromotionStats($globalStats);
        } else {
            $this->displaySimpleStats($globalStats);
        }
        
        $this->newLine();
        $this->info("🎉 Actualización completada exitosamente");
    }
}
