<?php

namespace App\Services\Orders;

use App\Exceptions\Orders\ProductNotFoundException;
use App\Exceptions\Orders\ShippingOutOfRangeException;
use App\Exceptions\Orders\ShippingQuoteException;
use App\Factories\ProviderFactory;
use App\Models\Cliente;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Responsabilidad única: cotizar envío de productos sin crear ningún pedido.
 *
 * Útil para mostrar costos de envío en el carrito antes del checkout.
 */
class ShippingQuoteService
{
    public function __construct(
        private readonly ProviderFactory $proveedorFactory,
    ) {}

    /**
     * @throws ShippingOutOfRangeException
     * @throws ShippingQuoteException
     */
    public function cotizar(
        array           $productos,
        Cliente         $cliente,
        string|int|null $almacenPreferido = null,
    ): array {
        $productosNormalizados = $this->normalizarProductos($productos);
        $productosPorProveedor = $this->agruparProductosPorProveedor($productosNormalizados);

        $cotizaciones       = [];
        $totalEnvio         = 0;
        $erroresProveedores = [];

        foreach ($productosPorProveedor as $proveedorId => $productosDelProveedor) {
            try {
                $servicio             = $this->proveedorFactory->crear($proveedorId);
                $productosParaCotizar = $servicio->prepararParaCotizacion($productosDelProveedor);
                $cotizacion           = $servicio->cotizarEnvio($productosParaCotizar, $cliente, $almacenPreferido);

                $cotizaciones[] = [
                    'proveedor'   => $servicio->obtenerNombre(),
                    'costo_envio' => $cotizacion->montoTotal,
                    'detalles'    => [
                        'subtotal'    => $cotizacion->subtotal,
                        'iva'         => $cotizacion->iva,
                        'monto_total' => $cotizacion->montoTotal,
                        'adicional'   => $cotizacion->detalles,
                    ],
                ];

                $totalEnvio += $cotizacion->montoTotal;

            } catch (ShippingOutOfRangeException $e) {
                throw $e; // fuera de rango: error crítico, no continuar
            } catch (ShippingQuoteException $e) {
                $erroresProveedores[] = [
                    'proveedor_id' => $proveedorId,
                    'error'        => $e->getMessage(),
                    'tipo'         => 'cotizacion',
                ];
                Log::warning('[ShippingQuote] Fallo cotización de envío', [
                    'proveedor_id' => $proveedorId,
                    'error'        => $e->getMessage(),
                ]);
            } catch (\Exception $e) {
                $erroresProveedores[] = [
                    'proveedor_id' => $proveedorId,
                    'error'        => $e->getMessage(),
                    'tipo'         => 'inesperado',
                ];
                Log::error('[ShippingQuote] Error inesperado al cotizar envío', [
                    'proveedor_id' => $proveedorId,
                    'error'        => $e->getMessage(),
                ]);
            }
        }

        if (empty($cotizaciones) && !empty($erroresProveedores)) {
            throw new ShippingQuoteException(
                'Todos los proveedores',
                'No se pudo cotizar envío con ningún proveedor disponible',
                $productos,
            );
        }

        return [
            'cotizaciones'        => $cotizaciones,
            'total_envio'         => round($totalEnvio, 2),
            'errores_proveedores' => $erroresProveedores,
        ];
    }

    // =========================================================================
    // PRIVADOS
    // =========================================================================

    private function normalizarProductos(array $productos): array
    {
        return collect($productos)->map(fn($p) => [
            'codigo_proveedor' => $p['clave'],
            'cantidad'         => (int) $p['cantidad'],
        ])->toArray();
    }

    /** @throws ProductNotFoundException */
    private function agruparProductosPorProveedor(array $productos): array
    {
        $codigos = collect($productos)->pluck('codigo_proveedor')->unique()->toArray();

        $mapping = DB::table('proveedor_productos')
            ->whereIn('codigo_proveedor', $codigos)
            ->select('codigo_proveedor', 'proveedor_id')
            ->get()
            ->keyBy('codigo_proveedor');

        $agrupados = [];

        foreach ($productos as $producto) {
            $row = $mapping->get($producto['codigo_proveedor']);

            if (!$row) {
                throw new ProductNotFoundException($producto['codigo_proveedor']);
            }

            $agrupados[$row->proveedor_id][] = $producto;
        }

        return $agrupados;
    }
}