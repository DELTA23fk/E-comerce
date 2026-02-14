<?php

namespace App\Console\Commands;

use App\Services\ProductoSyncService;
use Illuminate\Console\Command;

/**
 * Comando de Sincronización Inicial de Productos CVA
 * 
 * Realiza la primera carga completa de productos desde CVA:
 * - Crea productos nuevos
 * - Establece relaciones de catálogo
 * - Inserta precios iniciales
 * - Inserta stock inicial
 * - Inserta promociones activas
 * - Descarga imágenes
 * 
 * Uso:
 * php artisan sync:cva-products --all                    # Todas las páginas automáticamente
 * php artisan sync:cva-products --page=5                 # Desde página específica
 * php artisan sync:cva-products --upc=true --promos=true # Con filtros personalizados
 */
class SyncCvaProductosCommand extends Command
{
    protected $signature = 'sync:cva-products 
                            {--upc=true : Incluir productos con UPC}
                            {--dt=true : Incluir descripción técnica}
                            {--promos=true : Incluir promociones}
                            {--dc=true : Incluir disponibilidad en CD}
                            {--images=1 : Incluir imágenes}
                            {--MonedaPesos=true : Solo productos en pesos mexicanos}
                            {--completos=1 : Solo productos con datos completos}
                            {--exist=2 : Nivel de existencia (1=con stock, 2=todos)}
                            {--page=1 : Página inicial de sincronización}
                            {--all : Sincronizar todas las páginas automáticamente}';

    protected $description = 'Sincronización inicial completa de productos desde CVA (primera carga)';

    public function handle(ProductoSyncService $syncService)
    {
        $proveedorIdBd = 1; // ID del proveedor CVA en base de datos
        
        // Construir filtros desde las opciones del comando
        $filtros = array_filter([
            'upc' => $this->option('upc'),
            'dt'  => $this->option('dt'),
            'dc'  => $this->option('dc'),
            'exist' => $this->option('exist'),
            'completos' => $this->option('completos'),
            'images' => $this->option('images'),
            'MonedaPesos' => $this->option('MonedaPesos'),
            'promos' => $this->option('promos')
        ]);

        $paginaInicial = (int) $this->option('page');
        $sincronizarTodo = $this->option('all');

        // Mostrar encabezado
        $this->mostrarEncabezado($filtros);

        $paginaActual = $paginaInicial;
        $totalPaginas = null;
        $paginasProcesadas = 0;
        $totalProductosProcesados = 0;

        // Loop principal de sincronización
        do {
            $this->mostrarEncabezadoPagina($paginaActual, $totalPaginas);
            
            $tiempoInicio = microtime(true);
            
            try {
                // Ejecutar sincronización de la página actual
                $infoPaginacion = $syncService->initialSyncCVA($proveedorIdBd, $filtros, $paginaActual);
                
                $duracion = round(microtime(true) - $tiempoInicio, 2);
                
                $paginasProcesadas++;

                if ($infoPaginacion) {
                    $totalPaginas = $infoPaginacion['total_paginas'];
                    $productosPagina = $infoPaginacion['productos_procesados'] ?? 0;
                    $totalProductosProcesados += $productosPagina;
                    
                    // Mostrar resultados de la página
                    $this->mostrarResultadosPagina($paginaActual, $productosPagina, $duracion);
                    
                    // Mostrar progreso general
                    $this->mostrarProgreso($paginaActual, $totalPaginas, $totalProductosProcesados);
                    
                    // Preguntar si continuar (solo si NO es modo --all)
                    if (!$sincronizarTodo && $paginaActual < $totalPaginas) {
                        $siguientePagina = $paginaActual + 1;
                        
                        if (!$this->confirm("¿Continuar con la página {$siguientePagina} de {$totalPaginas}?", true)) {
                            $this->warn("⏸️  Sincronización pausada por el usuario.");
                            break;
                        }
                    }
                    
                    // Incrementar página para siguiente iteración
                    $paginaActual++;
                    
                } else {
                    // No hay más datos
                    $this->info("✅ No hay más páginas para procesar");
                    break;
                }
                
            } catch (\Exception $e) {
                $this->error("❌ Error en página {$paginaActual}: " . $e->getMessage());
                $this->error($e->getTraceAsString());
                
                if ($this->confirm('¿Reintentar esta página?', true)) {
                    continue; // Reintentar la misma página
                }
                
                break; // Salir del loop
            }
            
        } while ($paginaActual <= $totalPaginas || ($sincronizarTodo && $infoPaginacion !== null));

        // Mostrar resumen final
        $this->mostrarResumenFinal($paginasProcesadas, $totalProductosProcesados);
        
        return Command::SUCCESS;
    }

    /**
     * Muestra el encabezado inicial del comando
     */
    protected function mostrarEncabezado(array $filtros): void
    {
        $this->newLine();
        $this->info("╔═══════════════════════════════════════════════════════════╗");
        $this->info("║      SINCRONIZACIÓN INICIAL DE PRODUCTOS CVA              ║");
        $this->info("╚═══════════════════════════════════════════════════════════╝");
        $this->newLine();
        
        $this->info("🚀 Iniciando sincronización completa (primera carga)");
        
        if (!empty($filtros)) {
            $this->newLine();
            $this->info("📋 Filtros aplicados:");
            foreach ($filtros as $clave => $valor) {
                $this->line("  • {$clave}: {$valor}");
            }
        }
        
        $this->newLine();
        $this->line("📦 Esta operación creará:");
        $this->line("  ✓ Productos nuevos");
        $this->line("  ✓ Relaciones de catálogo (categorías, marcas, familias)");
        $this->line("  ✓ Precios iniciales");
        $this->line("  ✓ Stock inicial");
        $this->line("  ✓ Promociones activas");
        $this->line("  ✓ Imágenes de productos");
        $this->newLine();
    }

    /**
     * Muestra encabezado de cada página
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
     * Muestra los resultados de la página procesada
     */
    protected function mostrarResultadosPagina(int $pagina, int $productos, float $duracion): void
    {
        $this->newLine();
        $this->info("✅ Página {$pagina} completada exitosamente");
        $this->line("  ⏱️  Tiempo: {$duracion}s");
        $this->line("  📦 Productos procesados: {$productos}");
        $this->newLine();
    }

    /**
     * Muestra barra de progreso
     */
    protected function mostrarProgreso(int $actual, int $total, int $productosTotal): void
    {
        $porcentaje = round(($actual / $total) * 100, 1);
        $barraLlena = (int)($porcentaje / 2);
        $barraVacia = 50 - $barraLlena;
        $barraProgreso = str_repeat('█', $barraLlena) . str_repeat('░', $barraVacia);
        
        $this->line("📊 Progreso general: [{$barraProgreso}] {$porcentaje}%");
        $this->line("📈 Total de productos sincronizados: {$productosTotal}");
        $this->newLine();
    }

    /**
     * Muestra resumen final de la sincronización
     */
    protected function mostrarResumenFinal(int $paginasProcesadas, int $totalProductos): void
    {
        $this->newLine();
        $this->info("╔═══════════════════════════════════════════════════════════╗");
        $this->info("║          RESUMEN FINAL DE SINCRONIZACIÓN                  ║");
        $this->info("╚═══════════════════════════════════════════════════════════╝");
        $this->newLine();
        
        $this->line("  📄 Total de páginas procesadas: {$paginasProcesadas}");
        $this->line("  📦 Total de productos sincronizados: {$totalProductos}");
        
        $this->newLine();
        $this->info("🎉 Sincronización inicial completada exitosamente");
        $this->newLine();
        
        $this->comment("💡 Para actualizar productos existentes, use:");
        $this->line("   php artisan update:cva-products --type=all");
    }
}