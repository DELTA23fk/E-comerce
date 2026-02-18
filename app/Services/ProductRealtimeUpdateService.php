<?php

namespace App\Services;

use App\Models\Proveedor;
use Illuminate\Support\Facades\Log;

class ProductRealtimeUpdateService
{
   protected ProductoSyncService $syncService;

    public function __construct(ProductoSyncService $syncService)
    {
        $this->syncService = $syncService;
    }

    /**
     * Actualiza un producto específico desde su API de proveedor
     * 
     * @param int $productoId ID del producto
     * @param int $proveedorId ID del proveedor
     * @return bool True si se actualizó correctamente
     */
    public function actualizarProductoDesdeAPI(int $productoId, int $proveedorId): bool
    {
        try {
            // Obtener información del proveedor
            $proveedor = Proveedor::find($proveedorId);
            
            if (!$proveedor) {
                Log::warning("Proveedor no encontrado", ['proveedor_id' => $proveedorId]);
                return false;
            }

            // Obtener el codigo del producto para ese proveedor
            $proveedorProducto = \App\Models\ProveedorProducto::where('producto_id', $productoId)
                ->where('proveedor_id', $proveedorId)
                ->first();

            if (!$proveedorProducto) {
                Log::warning("Relación proveedor-producto no encontrada", [
                    'producto_id' => $productoId,
                    'proveedor_id' => $proveedorId
                ]);
                return false;
            }

            // Match del proveedor con su API
            $updated = match(strtolower($proveedor->codigo_proveedor)) {
                'cva' => $this->actualizarDesdeCVA($proveedorProducto->codigo_proveedor, $proveedorId),
                // Aquí puedes agregar más proveedores
                // 'CT' => $this->actualizarDesdeCT($proveedorProducto->codigo_proveedor, $proveedorId),
                // 'INGRAM' => $this->actualizarDesdeIngram($proveedorProducto->codigo_proveedor, $proveedorId),
                // 'TECH_DATA' => $this->actualizarDesdeTechData($proveedorProducto->codigo_proveedor, $proveedorId),
                default => $this->proveedorNoSoportado($proveedor->codigo_proveedor)
            };

            if ($updated) {
                Log::info("Producto actualizado desde API", [
                    'producto_id' => $productoId,
                    'proveedor_id' => $proveedorId,
                    'proveedor_codigo' => $proveedor->codigo_proveedor
                ]);
            }

            return $updated;

        } catch (\Exception $e) {
            Log::error("Error actualizando producto desde API", [
                'producto_id' => $productoId,
                'proveedor_id' => $proveedorId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Actualiza producto desde CVA
     * 
     * @param string $articuloId ID del artículo en CVA
     * @param int $proveedorId ID del proveedor en BD
     * @return bool
     */
    protected function actualizarDesdeCVA(string $articuloId, int $proveedorId): bool
    {
        try {
            $result = $this->syncService->updateSingleArticleCVA($proveedorId, $articuloId);
            return $result['success'] ?? false;
        } catch (\Exception $e) {
            Log::error("Error actualizando desde CVA", [
                'articulo_id' => $articuloId,
                'proveedor_id' => $proveedorId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

   

    /**
     * Maneja proveedores no soportados
     * 
     * @param string $codigoProveedor Código del proveedor
     * @return bool
     */
    protected function proveedorNoSoportado(string $codigoProveedor): bool
    {
        Log::warning("Proveedor no soportado para actualización automática", [
            'codigo_proveedor' => $codigoProveedor
        ]);
        return false;
    }

    /**
     * Actualiza todos los proveedores de un producto
     * 
     * @param int $productoId ID del producto
     * @return array Estadísticas de actualización
     */
    public function actualizarTodosLosProveedores(int $productoId): array
    {
        $stats = [
            'total' => 0,
            'actualizados' => 0,
            'errores' => 0,
            'no_soportados' => 0
        ];

        try {
            $proveedoresProducto = \App\Models\ProveedorProducto::where('producto_id', $productoId)
                ->with('proveedor')
                ->get();

            $stats['total'] = $proveedoresProducto->count();

            if ($stats['total'] === 0) {
                Log::info("Producto sin proveedores asociados", ['producto_id' => $productoId]);
                return $stats;
            }

            foreach ($proveedoresProducto as $pp) {
                $actualizado = $this->actualizarProductoDesdeAPI($productoId, $pp->proveedor_id);
                
                if ($actualizado) {
                    $stats['actualizados']++;
                } else {
                    // Verificar si fue error o proveedor no soportado
                    $proveedor = $pp->proveedor;
                    if ($proveedor && !in_array($proveedor->codigo_proveedor, ['cva'])) {
                        $stats['no_soportados']++;
                    } else {
                        $stats['errores']++;
                    }
                }
            }

            Log::info("Actualización masiva de proveedores completada", [
                'producto_id' => $productoId,
                'stats' => $stats
            ]);

        } catch (\Exception $e) {
            Log::error("Error en actualización masiva de proveedores", [
                'producto_id' => $productoId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $stats['errores']++;
        }

        return $stats;
    }

    /**
     * Actualiza un proveedor específico de un producto
     * 
     * @param int $productoId ID del producto
     * @param int $proveedorId ID del proveedor
     * @return array Resultado de la actualización
     */
    public function actualizarProveedorEspecifico(int $productoId, int $proveedorId): array
    {
        try {
            $actualizado = $this->actualizarProductoDesdeAPI($productoId, $proveedorId);
            
            return [
                'success' => $actualizado,
                'producto_id' => $productoId,
                'proveedor_id' => $proveedorId,
                'message' => $actualizado 
                    ? 'Proveedor actualizado correctamente' 
                    : 'No se pudo actualizar el proveedor'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'producto_id' => $productoId,
                'proveedor_id' => $proveedorId,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Verifica si un proveedor está soportado para actualización automática
     * 
     * @param string $codigoProveedor Código del proveedor
     * @return bool
     */
    public function esProveedorSoportado(string $codigoProveedor): bool
    {
        return match($codigoProveedor) {
            'CVA' => true,
            // 'CT' => true,
            // 'INGRAM' => true,
            // 'TECH_DATA' => true,
            default => false
        };
    }

    /**
     * Obtiene la lista de proveedores soportados
     * 
     * @return array
     */
    public function getProveedoresSoportados(): array
    {
        return [
            'CVA',
            // Descomentar cuando se implementen:
            // 'CT',
            // 'INGRAM',
            // 'TECH_DATA',
        ];
    }

    /**
     * Valida si se debe actualizar un producto
     * Útil para implementar lógica de caché o throttling
     * 
     * @param int $productoId ID del producto
     * @param int $proveedorId ID del proveedor
     * @return bool
     */
    public function debeActualizar(int $productoId, int $proveedorId): bool
    {
        try {
            // Obtener la última actualización
            $proveedorProducto = \App\Models\ProveedorProducto::where('producto_id', $productoId)
                ->where('proveedor_id', $proveedorId)
                ->first();

            if (!$proveedorProducto) {
                return false;
            }

            // Si no tiene fecha de actualización, actualizar
            if (!$proveedorProducto->ultima_actualizacion) {
                return true;
            }

            // Actualizar si han pasado más de 5 minutos
            $minutosDesdeActualizacion = now()->diffInMinutes($proveedorProducto->ultima_actualizacion);
            
            return $minutosDesdeActualizacion >= 5;

        } catch (\Exception $e) {
            Log::error("Error verificando si debe actualizar", [
                'producto_id' => $productoId,
                'proveedor_id' => $proveedorId,
                'error' => $e->getMessage()
            ]);
            // En caso de error, mejor no actualizar
            return false;
        }
    }

    /**
     * Actualiza todos los proveedores con throttling inteligente
     * Solo actualiza si han pasado más de 5 minutos desde la última actualización
     * 
     * @param int $productoId ID del producto
     * @return array Estadísticas de actualización
     */
    public function actualizarTodosLosProveedoresConThrottling(int $productoId): array
    {
        $stats = [
            'total' => 0,
            'actualizados' => 0,
            'omitidos' => 0,
            'errores' => 0,
            'no_soportados' => 0
        ];

        try {
            $proveedoresProducto = \App\Models\ProveedorProducto::where('producto_id', $productoId)
                ->with('proveedor')
                ->get();

            $stats['total'] = $proveedoresProducto->count();

            foreach ($proveedoresProducto as $pp) {
                // Verificar si debe actualizar
                if (!$this->debeActualizar($productoId, $pp->proveedor_id)) {
                    $stats['omitidos']++;
                    Log::debug("Actualización omitida por throttling", [
                        'producto_id' => $productoId,
                        'proveedor_id' => $pp->proveedor_id,
                        'ultima_actualizacion' => $pp->ultima_actualizacion
                    ]);
                    continue;
                }

                $actualizado = $this->actualizarProductoDesdeAPI($productoId, $pp->proveedor_id);
                
                if ($actualizado) {
                    $stats['actualizados']++;
                } else {
                    $proveedor = $pp->proveedor;
                    if ($proveedor && !$this->esProveedorSoportado($proveedor->codigo_proveedor)) {
                        $stats['no_soportados']++;
                    } else {
                        $stats['errores']++;
                    }
                }
            }

            Log::info("Actualización masiva con throttling completada", [
                'producto_id' => $productoId,
                'stats' => $stats
            ]);

        } catch (\Exception $e) {
            Log::error("Error en actualización masiva con throttling", [
                'producto_id' => $productoId,
                'error' => $e->getMessage()
            ]);
            $stats['errores']++;
        }

        return $stats;
    }
}
