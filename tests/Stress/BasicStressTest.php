<?php

namespace Tests\Stress;

use Tests\TestCase;
use Laravel\Sanctum\Sanctum;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Tests de estrés para endpoints - SIN CACHE
 * 
 * Objetivo: Medir tiempos de respuesta en estado base (sin cache)
 * para validar mejora cuando se implemente caching
 */
class BasicStressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Autenticar usuario
        $user = User::factory()->create();
        Sanctum::actingAs($user);
    }

    /**
     * Test: 100 requests al endpoint de salud de la app
     * (No requiere datos, espera siempre 200)
     */
    public function test_health_check_100_requests(): void
    {
        $requestCount = 100;
        $times = [];

        echo "\n\n📊 STRESS TEST: 100 requests GET /sanctum/csrf-cookie";
        echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";

        for ($i = 1; $i <= $requestCount; $i++) {
            $start = microtime(true);

            // Endpoint simple que no requiere datos
            $this->get("/sanctum/csrf-cookie")->assertStatus(204);

            $duration = (microtime(true) - $start) * 1000;
            $times[] = $duration;

            if ($i % 20 === 0) {
                echo "\n✓ {$i} requests completados";
            }
        }

        // Calcular estadísticas
        $avg = array_sum($times) / count($times);
        $min = min($times);
        $max = max($times);

        echo "\n\n📈 RESULTADOS:";
        echo "\n  Total requests: {$requestCount}";
        echo "\n  Promedio: " . number_format($avg, 2) . "ms";
        echo "\n  Mínimo: " . number_format($min, 2) . "ms";
        echo "\n  Máximo: " . number_format($max, 2) . "ms";

        $this->assertTrue(true);
    }

    /**
     * Test: 50 requests a endpoint de perfil autenticado
     */
    public function test_auth_profile_50_requests(): void
    {
        $requestCount = 50;
        $times = [];

        echo "\n\n📊 STRESS TEST: 50 requests GET /api/v1/auth/profile";
        echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";

        for ($i = 1; $i <= $requestCount; $i++) {
            $start = microtime(true);

            $response = $this->getJson("/api/v1/auth/profile");
            // No asserción de status - solo medimos tiempos

            $duration = (microtime(true) - $start) * 1000;
            $times[] = $duration;

            if ($i % 10 === 0) {
                echo "\n✓ {$i} requests completados - Último: {$duration}ms";
            }
        }

        $avg = array_sum($times) / count($times);
        $min = min($times);
        $max = max($times);

        echo "\n\n📈 RESULTADOS:";
        echo "\n  Total requests: {$requestCount}";
        echo "\n  Promedio: " . number_format($avg, 2) . "ms";
        echo "\n  Mínimo: " . number_format($min, 2) . "ms";
        echo "\n  Máximo: " . number_format($max, 2) . "ms";

        $this->assertTrue(true);
    }

    /**
     * Test: Comparar tiempos de endpoints con diferentes cargas
     */
    public function test_endpoint_response_time_comparison(): void
    {
        echo "\n\n📊 STRESS TEST: Comparativa de endpoints";
        echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";

        $endpoints = [
            '/sanctum/csrf-cookie' => 20,
            '/api/v1/auth/profile' => 20,
        ];

        $results = [];

        foreach ($endpoints as $endpoint => $count) {
            echo "\n\n📍 Endpoint: {$endpoint}";
            $times = [];

            for ($i = 0; $i < $count; $i++) {
                $start = microtime(true);
                
                if (strpos($endpoint, 'sanctum') !== false) {
                    $this->get($endpoint);
                } else {
                    $this->getJson($endpoint);
                }

                $times[] = (microtime(true) - $start) * 1000;
            }

            $avg = array_sum($times) / count($times);
            $results[$endpoint] = $avg;

            echo "\n  Promedio: " . number_format($avg, 2) . "ms";
        }

        echo "\n\n📊 COMPARACIÓN:";
        arsort($results);
        foreach ($results as $ep => $time) {
            echo "\n  {$ep}: " . number_format($time, 2) . "ms";
        }

        $this->assertTrue(true);
    }

    /**
     * Test: Carga sostenida (simular múltiples usuarios)
     */
    public function test_sustained_load_simulation(): void
    {
        $requestCount = 200;
        $times = [];
        $errors = 0;

        echo "\n\n📊 STRESS TEST: 200 requests en carga sostenida";
        echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";

        for ($i = 1; $i <= $requestCount; $i++) {
            $start = microtime(true);

            $response = $this->getJson("/api/v1/auth/profile");
            
            if ($response->status() !== 200) {
                $errors++;
            }

            $duration = (microtime(true) - $start) * 1000;
            $times[] = $duration;

            if ($i % 50 === 0) {
                $avg_so_far = array_sum(array_slice($times, -50)) / 50;
                echo "\n✓ {$i} requests - Promedio últimos 50: " . number_format($avg_so_far, 2) . "ms";
            }
        }

        $avg = array_sum($times) / count($times);
        $p95 = $this->calculatePercentile($times, 95);
        $p99 = $this->calculatePercentile($times, 99);
        $successRate = (($requestCount - $errors) / $requestCount) * 100;

        echo "\n\n📈 RESULTADOS:";
        echo "\n  Total requests: {$requestCount}";
        echo "\n  Promedio: " . number_format($avg, 2) . "ms";
        echo "\n  P95: " . number_format($p95, 2) . "ms";
        echo "\n  P99: " . number_format($p99, 2) . "ms";
        echo "\n  Tasa de éxito: " . number_format($successRate, 1) . "%";
        echo "\n  Errores: {$errors}";

        $this->assertTrue(true);
    }

    /**
     * Helper: Calcular percentil
     */
    private function calculatePercentile(array $times, float $percentile): float
    {
        sort($times);
        $index = ceil((count($times) * $percentile) / 100) - 1;
        return $times[max(0, $index)];
    }
}
