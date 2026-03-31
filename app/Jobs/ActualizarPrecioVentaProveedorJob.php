<?php

namespace App\Jobs;

use App\Factories\ProductoFactory;
use App\Models\Proveedor;
use App\Models\ProveedorProducto;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ActualizarPrecioVentaProveedorJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries   = 3;
    public int $backoff = 60;

    public function __construct(
        private readonly int $proveedorId,
        private readonly int $nuevoPorcentajeUtilidad,
    ) {}

    /**
     * Identifica de manera única este job por proveedor.
     *
     * Esto evita que dos jobs para el mismo proveedor se ejecuten
     * simultáneamente y evita conflictos de actualización.
     */
    public function uniqueId(): string
    {
        return (string) $this->proveedorId;
    }

    /**
     * Mantiene la unicidad del job durante su tiempo de ejecución.
     */
    public function uniqueFor(): int
    {
        return $this->timeout;
    }

    /**
     * Ejecuta la actualización en segundo plano de los precios de venta
     * de todos los productos del proveedor.
     *
     * Actualiza el porcentaje de utilidad del proveedor, invalida el cache
     * de utilidad y recalcula el precio_venta para cada precio relacionado.
     */
    public function handle(): void
    {
        /** @var Proveedor|null $proveedor */
        $proveedor = Proveedor::find($this->proveedorId);

        if (! $proveedor) {
            Log::warning('[ActualizarPrecioVentaProveedorJob] Proveedor no encontrado', [
                'proveedor_id' => $this->proveedorId,
            ]);
            return;
        }

        $inicio = microtime(true);
        $updatedCount = 0;

        Log::info('[ActualizarPrecioVentaProveedorJob] Iniciando', [
            'proveedor_id' => $this->proveedorId,
            'nuevo_porcentaje_utilidad' => $this->nuevoPorcentajeUtilidad,
        ]);
        //actualizar el porcentaje de utilidad del proveedor
        $proveedor->update(['porcentaje_utilidad' => $this->nuevoPorcentajeUtilidad]);
        //limpiar cache de utilidad para forzar recálculo en próximos procesos
        cache::forget("proveedor_utilidad_{$proveedor->codigo_proveedor}");

        ProveedorProducto::where('proveedor_id', $proveedor->id)
            ->with('precio')
            ->chunk(100, function ($proveedorProductos) use (&$updatedCount) {
                foreach ($proveedorProductos as $proveedorProducto) {
                    $precio = $proveedorProducto->precio;

                    if (! $precio || $precio->precio_venta === null) {
                        continue;
                    }

                    $monedaVenta = strtoupper(trim((string) $precio->moneda_venta ?: 'MXN'));
                    $monedaBaseProducto = strtoupper(trim((string) $precio->moneda_base_producto ?: 'MXN'));
                    $usaPrecioBaseDirecto = $monedaVenta === $monedaBaseProducto;

                    $nuevoPrecioVenta = $this->calcularNuevoPrecioVenta($precio, $usaPrecioBaseDirecto);

                    if (bccomp((string) $precio->precio_venta, $nuevoPrecioVenta, 2) === 0
                        && (int) $precio->porcentaje_utilidad === $this->nuevoPorcentajeUtilidad) {
                        continue;
                    }

                    $datosActualizacion = [
                        'precio_anterior' => $precio->precio_venta,
                        'precio_venta' => $nuevoPrecioVenta,
                        'porcentaje_utilidad' => $this->nuevoPorcentajeUtilidad,
                        'ultima_actualizacion' => now(),
                    ];

                    if ($usaPrecioBaseDirecto) {
                        $datosActualizacion['precio_base_producto'] = (string) $precio->precio_base_producto ?: $this->obtenerPrecioBaseDesdePrecioVenta($precio);
                    } else {
                        $datosActualizacion['precio_base_producto'] = $this->obtenerPrecioBaseDesdePrecioVenta($precio);
                    }

                    $precio->update($datosActualizacion);
                    $updatedCount++;
                }
            });

        Cache::forget('proveedores:all');
        Cache::forget('proveedores:activos');

        Log::info('[ActualizarPrecioVentaProveedorJob] Completado', [
            'proveedor_id' => $this->proveedorId,
            'updated_count' => $updatedCount,
            'elapsed_seconds' => round(microtime(true) - $inicio, 2),
        ]);
    }

    /**
     * Determina el nuevo precio de venta según la moneda base.
     *
     * Si la moneda de venta coincide con la moneda base, usa el precio
     * base directo. En caso contrario, recupera el precio base desde
     * el precio de venta actual descontando la utilidad aplicada.
     */
    private function calcularNuevoPrecioVenta($precio, bool $usaPrecioBaseDirecto): string
    {
        if ($usaPrecioBaseDirecto) {
            $precioBase = (string) $precio->precio_base_producto;

            if ($precioBase === '') {
                $precioBase = $this->obtenerPrecioBaseDesdePrecioVenta($precio);
            }

            return ProductoFactory::calcularPrecioVenta(
                $precioBase,
                $this->nuevoPorcentajeUtilidad
            );
        }

        $precioBase = $this->obtenerPrecioBaseDesdePrecioVenta($precio);

        return ProductoFactory::calcularPrecioVenta(
            $precioBase,
            $this->nuevoPorcentajeUtilidad
        );
    }

    /**
     * Calcula el precio base original antes de utilidad.
     *
     * Si existe un porcentaje de utilidad actual, divide el precio de
     * venta por el factor correspondiente para recuperar el precio base.
     */
    private function obtenerPrecioBaseDesdePrecioVenta($precio): string
    {
        $porcentajeActual = (int) $precio->porcentaje_utilidad;

        if ($porcentajeActual === 0) {
            return (string) $precio->precio_venta;
        }

        $factorActual = bcadd('1', bcdiv((string) $porcentajeActual, '100', 6), 6);

        return bcdiv((string) $precio->precio_venta, $factorActual, 4);
    }

    /**
     * Registra el fallo del job una vez que se agotan los reintentos.
     */
    public function failed(\Throwable $e): void
    {
        Log::critical('[ActualizarPrecioVentaProveedorJob] Falló tras todos los reintentos', [
            'proveedor_id' => $this->proveedorId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}
