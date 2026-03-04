<?php

namespace Tests\Stress;

use Tests\TestCase;
use Laravel\Sanctum\Sanctum;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * STRESS TESTS - Medición de tiempos SIN CACHE
 * 
 * Mide velocidad de endpoints bajo carga
 * Sin validación de datos - solo mediición de tiempos
 */
class ProductEndpointStressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        Sanctum::actingAs($user);
    }

    /**
     * 100 requests a GET /api/v1/productos/1
     */
    public function test_product_get_100_requests(): void
    {
        $times = [];
        echo "\n\n 💪 100 requests GET /api/v1/productos/1";
        echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";

        for ($i = 1; $i <= 100; $i++) {
            $start = microtime(true);
            $this->getJson("/api/v1/productos/1");
            $times[] = (microtime(true) - $start) * 1000;
            if ($i % 20 === 0) echo "\n ✓ {$i}";
        }
        
        $this->stats($times);
        $this->assertTrue(true);
    }

    /**
     * 50 requests CON include_proveedores
     */
    public function test_product_with_providers_50_requests(): void
    {
        $times = [];
        echo "\n\n 💪 50 requests GET /api/v1/productos/1?include_proveedores=true";
        echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";

        for ($i = 1; $i <= 50; $i++) {
            $start = microtime(true);
            $this->getJson("/api/v1/productos/1?include_proveedores=true");
            $times[] = (microtime(true) - $start) * 1000;
            if ($i % 10 === 0) echo "\n ✓ {$i}";
        }
        
        $this->stats($times);
        $this->assertTrue(true);
    }

    /**
     * 100 requests a 10 productos diferentes
     */
    public function test_multiple_products_100_requests(): void
    {
        $times = [];
        echo "\n\n 💪 100 requests (10 productos diferentes)";
        echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";

        for ($i = 1; $i <= 100; $i++) {
            $pid = (($i - 1) % 10) + 1;
            $start = microtime(true);
            $this->getJson("/api/v1/productos/{$pid}");
            $times[] = (microtime(true) - $start) * 1000;
            if ($i % 25 === 0) echo "\n ✓ {$i}";
        }
        
        $this->stats($times);
        $this->assertTrue(true);
    }

    /**
     * 50 búsquedas por código de barras
     */
    public function test_barcode_search_50_requests(): void
    {
        $times = [];
        echo "\n\n 💪 50 requests GET /api/v1/productos/barras/...";
        echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";

        for ($i = 1; $i <= 50; $i++) {
            $start = microtime(true);
            $this->getJson("/api/v1/productos/barras/7501000001");
            $times[] = (microtime(true) - $start) * 1000;
            if ($i % 10 === 0) echo "\n ✓ {$i}";
        }
        
        $this->stats($times);
        $this->assertTrue(true);
    }

    /**
     * Comparar CON vs SIN proveedores
     */
    public function test_comparison_providers(): void
    {
        echo "\n\n 💪 Comparar: SIN vs CON proveedores";
        echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";

        echo "\n 1️⃣  SIN (30):";
        $times1 = [];
        for ($i = 0; $i < 30; $i++) {
            $start = microtime(true);
            $this->getJson("/api/v1/productos/1");
            $times1[] = (microtime(true) - $start) * 1000;
        }
        $avg1 = array_sum($times1) / count($times1);
        echo "\n    " . number_format($avg1, 2) . "ms";

        echo "\n 2️⃣  CON (30):";
        $times2 = [];
        for ($i = 0; $i < 30; $i++) {
            $start = microtime(true);
            $this->getJson("/api/v1/productos/1?include_proveedores=true");
            $times2[] = (microtime(true) - $start) * 1000;
        }
        $avg2 = array_sum($times2) / count($times2);
        echo "\n    " . number_format($avg2, 2) . "ms";

        $diff = $avg2 - $avg1;
        $pct = ($avg1 > 0) ? ($diff / $avg1) * 100 : 0;
        echo "\n\n 📊 +" . number_format($diff, 2) . "ms (+" . number_format($pct, 1) . "%)";

        $this->assertTrue(true);
    }

    /**
     * 30 requests listado paginado
     */
    public function test_paginated_list_30_requests(): void
    {
        $times = [];
        echo "\n\n 💪 30 requests GET /api/v1/productos (paginado)";
        echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";

        for ($i = 1; $i <= 30; $i++) {
            $page = (($i - 1) % 5) + 1;
            $start = microtime(true);
            $this->getJson("/api/v1/productos?page={$page}");
            $times[] = (microtime(true) - $start) * 1000;
            if ($i % 10 === 0) echo "\n ✓ {$i}";
        }
        
        $this->stats($times);
        $this->assertTrue(true);
    }

    /**
     * 20 requests filtrados
     */
    public function test_filtered_search_20_requests(): void
    {
        $times = [];
        echo "\n\n 💪 20 requests GET /api/v1/productos (filtrado)";
        echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";

        for ($i = 0; $i < 20; $i++) {
            $start = microtime(true);
            $this->getJson("/api/v1/productos?marca_id=1");
            $times[] = (microtime(true) - $start) * 1000;
            if (($i + 1) % 10 === 0) echo "\n ✓ " . ($i + 1);
        }
        
        $this->stats($times);
        $this->assertTrue(true);
    }

    private function stats(array $times): void
    {
        $avg = array_sum($times) / count($times);
        $min = min($times);
        $max = max($times);

        echo "\n\n Ø=" . number_format($avg, 2) . "ms | min=" . number_format($min, 2) . "ms | max=" . number_format($max, 2) . "ms";
    }
}
