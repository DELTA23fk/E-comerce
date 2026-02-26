<?php

namespace App\Providers;

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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
