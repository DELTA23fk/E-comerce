<?php

namespace App\Providers;

use App\Factories\PaymentGatewayFactory;
use App\Factories\ProviderFactory;
use App\Services\Orders\OrchestratorOrdersService;
use App\Services\ProductCatalogService;
use App\Services\ProductFilterService;
use App\Services\ProductOfferService;
use App\Services\ProductProviderService;
use App\Services\ProductRelationService;
use App\Services\ProductResponseService;
use App\Services\ProductSearchService;
use App\Services\ProductService;
use App\Services\ProductStockService;
use App\Services\Providers\Cva\CvaSyncService;
use App\Services\Sync\ProductoPersistenceService;
use App\Services\Sync\ProductoSyncOrchestrator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Services principales
        $this->app->singleton(ProductService::class);
        $this->app->singleton(ProductFilterService::class);
        $this->app->singleton(ProductRelationService::class);
        $this->app->singleton(ProductResponseService::class);
        
        // Services especializados
        $this->app->singleton(ProductSearchService::class);
        $this->app->singleton(ProductCatalogService::class);
        $this->app->singleton(ProductProviderService::class);
        $this->app->singleton(ProductStockService::class);
        $this->app->singleton(ProductOfferService::class);

        $this->app->singleton(ProductoPersistenceService::class);

        $this->app->singleton(ProductoSyncOrchestrator::class, function ($app) {
            return new ProductoSyncOrchestrator(
                persistencia: $app->make(ProductoPersistenceService::class),
                proveedores: [
                    $app->make(CvaSyncService::class),
                ],
            );
        });

          // ── Servicios de proveedores ─────────────────────────────────────────
        // No se declaran singleton explícitamente.
        // Al ser inyectados en ProviderFactory (singleton), Laravel los resuelve
        // una sola vez y viven el mismo ciclo de vida que el factory.
        // Si se añade un nuevo proveedor, solo se inyecta aquí — nada más cambia.

        // ── ProviderFactory ──────────────────────────────────────────────────
        // Singleton seguro: $mapa es lazy y se construye una vez.
        // Los servicios inyectados no tienen estado mutable en $this.
        $this->app->singleton(ProviderFactory::class);

        // ── PaymentGatewayFactory ────────────────────────────────────────────
        // Singleton seguro: solo lee config, no tiene estado mutable.
        $this->app->singleton(PaymentGatewayFactory::class);

        // ── OrchestratorOrdersService ────────────────────────────────────────
        // Singleton seguro: solo guarda los dos factories (readonly).
        // Todo el estado de cada pedido viaja como parámetros de método.
        $this->app->singleton(OrchestratorOrdersService::class);

    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
