<?php

namespace App\Services;

use App\Models\ProveedorProducto;
use App\Models\Proveedor;
use App\Services\Sync\ProductoSyncOrchestrator;
use Illuminate\Support\Facades\Log;

/**
 * Servicio de actualización en tiempo real de productos.
 *
 * Antes: dependía de ProductoSyncService y tenía un match() hardcodeado por proveedor.
 * Ahora: delega todo al orquestador. Para soportar un nuevo proveedor solo hay que
 * registrarlo en AppServiceProvider; este servicio NO necesita cambios.
 */
class ProductRealtimeUpdateService
{
    public function __construct(
        private readonly ProductoSyncOrchestrator $orquestador,
    ) {}

    // =========================================================================
    // API PÚBLICA
    // =========================================================================

    /**
     * Actualiza precio, stock y promociones de un producto desde su API.
     *
     * Equivalente al antiguo actualizarProductoDesdeAPI() + actualizarDesdeCVA().
     */
    public function actualizarProductoDesdeAPI(int $productoId, int $proveedorId): bool
    {
        try {
            [$proveedor, $proveedorProducto] = $this->resolverRelacion($productoId, $proveedorId);

            if (!$proveedor || !$proveedorProducto) {
                return false;
            }

            // El orquestador resuelve qué servicio usar por la clave del proveedor.
            // No hay match(), no hay if-elseif por proveedor.
            $resultado = $this->orquestador->syncArticulo(
                proveedorClave: strtolower($proveedor->codigo_proveedor),
                idExterno:      $proveedorProducto->codigo_proveedor,
            );

            if (!($resultado['exito'] ?? false)) {
                Log::warning('[RealtimeUpdate] No se pudo actualizar el artículo', $resultado);
                return false;
            }
            
            $proveedorProducto->updateQuietly(['ultima_actualizacion' => now()]);


            Log::info('[RealtimeUpdate] Producto actualizado', [
                'producto_id'  => $productoId,
                'proveedor_id' => $proveedorId,
                'clave'        => $proveedor->codigo_proveedor,
            ]);

            return true;

        } catch (\InvalidArgumentException $e) {
            // Proveedor no registrado en el orquestador
            Log::warning('[RealtimeUpdate] Proveedor no soportado', [
                'proveedor_id' => $proveedorId,
                'error'        => $e->getMessage(),
            ]);
            return false;

        } catch (\Throwable $e) {
            Log::error('[RealtimeUpdate] Error inesperado', [
                'producto_id'  => $productoId,
                'proveedor_id' => $proveedorId,
                'error'        => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Actualiza todos los proveedores asociados a un producto.
     */
    public function actualizarTodosLosProveedores(int $productoId): array
    {
        $stats = ['total' => 0, 'actualizados' => 0, 'errores' => 0, 'no_soportados' => 0];

        $relaciones = ProveedorProducto::where('producto_id', $productoId)
            ->with('proveedor')
            ->get();

        $stats['total'] = $relaciones->count();

        foreach ($relaciones as $pp) {
            $actualizado = $this->actualizarProductoDesdeAPI($productoId, $pp->proveedor_id);

            if ($actualizado) {
                $stats['actualizados']++;
            } elseif (!$this->esProveedorSoportado(strtolower($pp->proveedor->codigo_proveedor ?? ''))) {
                $stats['no_soportados']++;
            } else {
                $stats['errores']++;
            }
        }

        return $stats;
    }

    /**
     * Actualiza con throttling: omite si la última sync fue hace menos de $minutosMinimos.
     */
    public function actualizarTodosLosProveedoresConThrottling(int $productoId, int $minutosMinimos = 5): array
    {
        $stats = ['total' => 0, 'actualizados' => 0, 'omitidos' => 0, 'errores' => 0, 'no_soportados' => 0];

        $relaciones = ProveedorProducto::where('producto_id', $productoId)
            ->with('proveedor')
            ->get();

        $stats['total'] = $relaciones->count();

        foreach ($relaciones as $pp) {
            if (!$this->debeActualizar($pp, $minutosMinimos)) {
                $stats['omitidos']++;
                continue;
            }

            $actualizado = $this->actualizarProductoDesdeAPI($productoId, $pp->proveedor_id);

            if ($actualizado) {
                $stats['actualizados']++;
            } elseif (!$this->esProveedorSoportado(strtolower($pp->proveedor->codigo_proveedor ?? ''))) {
                $stats['no_soportados']++;
            } else {
                $stats['errores']++;
            }
        }

        return $stats;
    }

    /**
     * Actualiza un proveedor específico y devuelve un array de resultado.
     */
    public function actualizarProveedorEspecifico(int $productoId, int $proveedorId): array
    {
        $actualizado = $this->actualizarProductoDesdeAPI($productoId, $proveedorId);

        return [
            'success'      => $actualizado,
            'producto_id'  => $productoId,
            'proveedor_id' => $proveedorId,
            'message'      => $actualizado
                ? 'Proveedor actualizado correctamente'
                : 'No se pudo actualizar el proveedor',
        ];
    }

    // =========================================================================
    // UTILIDADES
    // =========================================================================

    /**
     * Verifica si un proveedor está registrado en el orquestador.
     * Ya no hay una lista hardcodeada; se consulta directamente al orquestador.
     */
    public function esProveedorSoportado(string $clave): bool
    {
        return in_array(strtolower($clave), $this->orquestador->proveedoresRegistrados(), true);
    }

    /**
     * Lista los proveedores soportados dinámicamente desde el orquestador.
     */
    public function getProveedoresSoportados(): array
    {
        return $this->orquestador->proveedoresRegistrados();
    }

    // =========================================================================
    // HELPERS PRIVADOS
    // =========================================================================

    /**
     * Resuelve proveedor y relación proveedor-producto con logs de advertencia.
     *
     * @return array{0: Proveedor|null, 1: ProveedorProducto|null}
     */
    private function resolverRelacion(int $productoId, int $proveedorId): array
    {
        $proveedor = Proveedor::find($proveedorId);

        if (!$proveedor) {
            Log::warning('[RealtimeUpdate] Proveedor no encontrado', ['proveedor_id' => $proveedorId]);
            return [null, null];
        }

        $pp = ProveedorProducto::where('producto_id', $productoId)
            ->where('proveedor_id', $proveedorId)
            ->first();

        if (!$pp) {
            Log::warning('[RealtimeUpdate] Relación proveedor-producto no encontrada', [
                'producto_id'  => $productoId,
                'proveedor_id' => $proveedorId,
            ]);
            return [$proveedor, null];
        }

        return [$proveedor, $pp];
    }

    /**
     * Determina si el producto debe actualizarse según el tiempo transcurrido.
     */
    private function debeActualizar(ProveedorProducto $pp, int $minutosMinimos): bool
    {
        if (!$pp->ultima_actualizacion) {
            return true;
        }

        // Parsear explícitamente para evitar problemas si el campo no está
        // casteado como datetime en el modelo (llegaría como string desde BD).
        // Se usa $fechaPasada->diffInMinutes(now()) para calcular cuántos
        // minutos han transcurrido desde la última actualización hasta ahora.
        $ultimaActualizacion = $pp->ultima_actualizacion instanceof \Carbon\Carbon
            ? $pp->ultima_actualizacion
            : \Carbon\Carbon::parse($pp->ultima_actualizacion);

        return $ultimaActualizacion->diffInMinutes(now()) >= $minutosMinimos;
    }
}