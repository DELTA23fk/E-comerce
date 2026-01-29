<?php

namespace App\Console\Commands;

use App\Services\ProductoSyncService;
use Illuminate\Console\Command;

class SyncCvaProductosCommand extends Command
{
    protected $signature = 'sync:cva-products {--upc=true} {--dt=true} {--promos=true} {--dc=true} {--images=1} {--MonedaPesos=true} {--completos=1} {--exist=2}   {--page=1 : Página inicial} {--all : Sincronizar todas las páginas automáticamente}';

    protected $description = 'Sincroniza productos de CVA con soporte para paginación y filtros';

    public function handle(ProductoSyncService $syncService)
    {
        $providerIdDb = 1; // ID del proveedor CVA en tu DB
        
        $filters = array_filter([
            'upc' => $this->option('upc'),
            'dt'  => $this->option('dt'),
            'dc'  => $this->option('dc'),
            'exist' => $this->option('exist'),
            'completos' => $this->option('completos'),
            'images'=> $this->option('images'),
            'MonedaPesos' => $this->option('MonedaPesos'),
            'promos' => $this->option('promos')
        ]);

        $startPage = (int) $this->option('page');
        $syncAll = $this->option('all');

        $this->info("🚀 Iniciando sincronización CVA...");
        
        if (!empty($filters)) {
            $this->info("Filtros aplicados: " . json_encode($filters));
        }

        $currentPage = $startPage;
        $totalPages = null;
        $totalProcessed = 0;
        $totalProductos = 0;


        do {
            $this->info("📄 Procesando página {$currentPage}" . ($totalPages ? " de {$totalPages}" : ""));
            
            $startTime = microtime(true);
            
            try {
                $paginationInfo = $syncService->initialSyncCVA($providerIdDb, $filters, $currentPage);
                
                $duration = round(microtime(true) - $startTime, 2);
                $this->info("✅ Página {$currentPage} procesada en {$duration}s");
                
                $totalProcessed++;

                if ($paginationInfo) {
                    $totalPages = $paginationInfo['total_paginas'];
                    $totalProductos += $paginationInfo['productos_procesados'] ?? 0;
                    
                    // Mostrar progreso
                    $progreso = round(($currentPage / $totalPages) * 100, 1);
                    $this->line("📊 Progreso: {$progreso}% ({$currentPage}/{$totalPages} páginas)");
                    $this->newLine();

                    
                    if (!$syncAll && $currentPage < $totalPages) {
                        // Preguntar ANTES de incrementar la página
                        $nextPage = $currentPage + 1;
                        
                        $shouldContinue = $this->confirm(
                            "¿Continuar con la página {$nextPage} de {$totalPages}?",
                            true // default es "yes"
                        );
                        
                        if (!$shouldContinue) {
                            $this->warn("⏸️  Sincronización pausada por el usuario.");
                            break;
                        }
                    }
                    
                    // Incrementar solo después de confirmar
                    $currentPage++;
                    
                } else {
                    // No hay más páginas
                    $this->info("✅ No hay más páginas para procesar");
                    break;
                }
                
            } catch (\Exception $e) {
                $this->error("❌ Error en página {$currentPage}: " . $e->getMessage());
                
                if ($this->confirm('¿Reintentar esta página?', true)) {
                    continue;
                }
                
                break;
            }
            
        } while ($currentPage <= $totalPages || $syncAll);

        $this->info("🎉 Sincronización completada. Total de páginas procesadas: {$totalProcessed}");
        
        return Command::SUCCESS;
    }
}
