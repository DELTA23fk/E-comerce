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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
