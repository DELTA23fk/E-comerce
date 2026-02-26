<?php

namespace App\Jobs;

use App\Models\Proveedor;
use App\Services\Sync\ProductoSyncOrchestrator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job Despachador de Actualizaciones
 *
 * Lee los proveedores ACTIVOS de BD, cruza con los registrados en el orquestador,
 * y lanza un ActualizarCatalogoJob independiente por cada uno.
 *
 * ─── OPTIMIZACIÓN POR TIPO DE PROVEEDOR ──────────────────────────────────────
 *
 * Proveedor UNIFICADO (ej. CVA — soportaConsultaUnificada = true):
 *   Un solo recorrido HTTP devuelve precio + stock + promos juntos.
 *   Sin importar qué tipo pida el scheduler, siempre se despacha 'todo':
 *   la llamada HTTP cuesta igual y se actualizan los tres datos de una vez.
 *
 *   Scheduler pide 'stock' cada 15 min:
 *   → CVA  unificado → ActualizarCatalogoJob('cva', 'todo')   ← precio+stock+promos en 1 HTTP
 *   → Exel separado  → ActualizarCatalogoJob('exel', 'stock') ← solo stock en 1 HTTP
 *
 * Proveedor SEPARADO (ej. Exel — soportaConsultaUnificada = false):
 *   Cada tipo tiene su propio endpoint. Se respeta el tipo pedido por el scheduler.
 *
 * ─── RESULTADO EN BD ─────────────────────────────────────────────────────────
 *
 * Con este esquema, cada vez que el scheduler lanza 'stock' cada 15 min:
 *   - CVA actualiza precio + stock + promos (sin costo adicional de HTTP)
 *   - Exel actualiza solo stock (su endpoint específico)
 *
 * Esto significa que para CVA, los schedulers de 'precios' y 'promociones'
 * son redundantes — ya se cubren con el de 'stock'. Son útiles únicamente
 * para proveedores con endpoints separados.
 */
class DespacharActualizacionesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries   = 3;

    public function __construct(
        private readonly string $tipo    = 'todo',
    ) {}

    // =========================================================================
    // EJECUCIÓN
    // =========================================================================

    public function handle(ProductoSyncOrchestrator $orquestador): void
    {
        $proveedoresActivos     = $this->obtenerProveedoresActivos();
        $proveedoresRegistrados = $orquestador->proveedoresRegistrados();

        // Solo despachar proveedores que estén activos EN BD y registrados EN el orquestador
        $proveedoresAEjecutar = array_intersect($proveedoresActivos, $proveedoresRegistrados);
        Log::debug('[DEBUG] Proveedores activos en BD: '    . implode(', ', $proveedoresActivos));
        Log::debug('[DEBUG] Proveedores en orquestador: '   . implode(', ', $proveedoresRegistrados));
        Log::debug('[DEBUG] Proveedores a ejecutar: '       . implode(', ', $proveedoresAEjecutar));
   
        if (empty($proveedoresAEjecutar)) {
            Log::warning('[DespacharActualizaciones] Sin proveedores para procesar', [
                'activos_en_bd'       => $proveedoresActivos,
                'en_orquestador'      => $proveedoresRegistrados,
            ]);
            return;
        }

        Log::info('[DespacharActualizaciones] Despachando jobs', [
            'tipo'        => $this->tipo,
            'proveedores' => $proveedoresAEjecutar,
        ]);

        foreach ($proveedoresAEjecutar as $clave) {
            $this->despacharParaProveedor($orquestador, $clave);
        }

        Log::info('[DespacharActualizaciones] Todos los jobs despachados', [
            'total' => count($proveedoresAEjecutar),
        ]);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function despacharParaProveedor(ProductoSyncOrchestrator $orquestador, string $clave): void
    {
        // Proveedor UNIFICADO: precio + stock + promos llegan en 1 recorrido HTTP.
        // Convertir cualquier tipo a 'todo' — misma llamada HTTP, tres actualizaciones.
        // Proveedor SEPARADO: respetar el tipo exacto que pidió el scheduler.
        $tipoEfectivo = $orquestador->proveedorSoportaConsultaUnificada($clave)
            ? 'todo'
            : $this->tipo;

        ActualizarCatalogoJob::dispatch($clave, $tipoEfectivo)
            ->onQueue('sync');
        Log::info('[DespacharActualizaciones] Job despachado', [
            'proveedor'     => $clave,
            'tipo_pedido'   => $this->tipo,
            'tipo_efectivo' => $tipoEfectivo,
            'optimizado'    => $tipoEfectivo === 'todo' && $this->tipo !== 'todo',
        ]);
    }

    private function obtenerProveedoresActivos(): array
    {
        return Proveedor::where('activo', true)
            ->pluck('codigo_proveedor')
            ->map(fn($c) => strtolower(trim($c)))
            ->toArray();
    }

    // =========================================================================
    // FALLO
    // =========================================================================

    public function failed(\Throwable $e): void
    {
        Log::critical('[DespacharActualizaciones] Job despachador falló', [
            'tipo'  => $this->tipo,
            'error' => $e->getMessage(),
        ]);
    }
}
