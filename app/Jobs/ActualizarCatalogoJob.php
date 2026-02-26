<?php

namespace App\Jobs;

use App\Services\Sync\ProductoSyncOrchestrator;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ActualizarCatalogoJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 7200;
    public int $tries   = 3;
    public int $backoff = 60;

    public function __construct(
        private readonly string $proveedorClave,
        private readonly string $tipo = 'todo',
    ) {}

    // =========================================================================
    // UNICIDAD — evita dos jobs del mismo proveedor corriendo simultáneamente
    // =========================================================================

    /**
     * Clave única por proveedor.
     * Si ya hay un ActualizarCatalogoJob('cva') en ejecución, el siguiente
     * intento se descarta automáticamente sin error.
     */
    public function uniqueId(): string
    {
        return $this->proveedorClave;
    }

    /**
     * Mantener el lock durante el tiempo máximo del job.
     * Debe coincidir con $timeout para que no expire antes de que termine.
     */
    public function uniqueFor(): int
    {
        return $this->timeout;
    }

    // =========================================================================
    // EJECUCIÓN
    // =========================================================================

    public function handle(ProductoSyncOrchestrator $orquestador): void
    {
        $inicio = microtime(true);

        Log::info("[ActualizarCatalogoJob] Iniciando", [
            'proveedor' => $this->proveedorClave,
            'tipo'      => $this->tipo,
        ]);

        $stats = match ($this->tipo) {
            'precios'     => ['precios'     => $orquestador->syncPrecios($this->proveedorClave)],
            'stock'       => ['stock'       => $orquestador->syncStock($this->proveedorClave)],
            'promociones' => ['promociones' => $orquestador->syncPromociones($this->proveedorClave)],
            'todo'        => $orquestador->syncTodo($this->proveedorClave),
            default       => throw new \InvalidArgumentException(
                "Tipo '{$this->tipo}' no válido. Usa: precios|stock|promociones|todo"
            ),
        };

        Log::info("[ActualizarCatalogoJob] Completado en " . round(microtime(true) - $inicio, 2) . "s", [
            'proveedor' => $this->proveedorClave,
            'tipo'      => $this->tipo,
            'stats'     => $stats,
        ]);
    }

    // =========================================================================
    // FALLO
    // =========================================================================

    public function failed(\Throwable $e): void
    {
        Log::critical('[ActualizarCatalogoJob] Falló tras todos los reintentos', [
            'proveedor' => $this->proveedorClave,
            'tipo'      => $this->tipo,
            'error'     => $e->getMessage(),
            'trace'     => $e->getTraceAsString(),
        ]);
    }
}