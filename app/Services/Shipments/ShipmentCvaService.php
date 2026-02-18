<?php

namespace App\Services\Shipments;

use App\Data\Request\RequestCotizarcionFleteCvaData;
use App\Data\Response\ApiResponseData;
use App\Exceptions\Cva\CvaStockException;
use App\Models\ProveedorProducto;
use App\Repository\CvaRepository;

class ShipmentCvaService
{
    
    private int $CP_CEDIS_GDL = 45640;
    private int $CP_SUCURSAL_GDL = 44900;
    private int $PAQUETERIAID = 4; //Paquetexpress

    public function __construct(
        private readonly CvaRepository $repositoryCva
    )
    {}

    public function cotizarPedido(RequestCotizarcionFleteCvaData $data)
    { 
        $codigosProductos = collect($data->productos)->pluck('clave')->toArray();
        
        $productosProveedor = ProveedorProducto::whereIn('codigo_proveedor', $codigosProductos)
            ->get()
            ->keyBy('codigo_proveedor');

        $productosCedis = [];
        $productosSucursal = [];
        $detalleDistribucion = [];

        foreach($data->productos as $producto) {
            $proveedorProducto = $productosProveedor->get($producto->clave);
            
            if (!$proveedorProducto) {
                throw new CvaStockException(
                    "Producto {$producto->clave} no encontrado",
                    404
                );
            }

            $cantidadSolicitada = $producto->cantidad;
            $stockCedis = $proveedorProducto->stock_cd;
            $stockSucursal = $proveedorProducto->stock;
            $stockTotal = $stockCedis + $stockSucursal;

            // Verificar si hay stock total suficiente
            if ($stockTotal < $cantidadSolicitada) {
                throw new CvaStockException(
                    "Stock total insuficiente para {$producto->clave}. " .
                    "Solicitado: {$cantidadSolicitada}, " .
                    "Disponible: {$stockTotal} (CEDIS: {$stockCedis}, Sucursal: {$stockSucursal})",
                    404
                );
            }

            // Caso 1: Todo el stock está en CEDIS
            if ($stockCedis >= $cantidadSolicitada) {
                $productosCedis[] = [
                    'clave' => $producto->clave,
                    'cantidad' => $cantidadSolicitada
                ];

                $detalleDistribucion[] = [
                    'clave' => $producto->clave,
                    'cantidad_solicitada' => $cantidadSolicitada,
                    'desde_cedis' => $cantidadSolicitada,
                    'desde_sucursal' => 0,
                    'origen' => 'CEDIS'
                ];
            }
            // Caso 2: Todo el stock está en Sucursal
            elseif ($stockSucursal >= $cantidadSolicitada) {
                $productosSucursal[] = [
                    'clave' => $producto->clave,
                    'cantidad' => $cantidadSolicitada
                ];

                $detalleDistribucion[] = [
                    'clave' => $producto->clave,
                    'cantidad_solicitada' => $cantidadSolicitada,
                    'desde_cedis' => 0,
                    'desde_sucursal' => $cantidadSolicitada,
                    'origen' => 'Sucursal'
                ];
            }
            // Caso 3: Dividir entre CEDIS y Sucursal
            else {
                $cantidadDesdeCedis = $stockCedis;
                $cantidadDesdeSucursal = $cantidadSolicitada - $stockCedis;

                if ($cantidadDesdeCedis > 0) {
                    $productosCedis[] = [
                        'clave' => $producto->clave,
                        'cantidad' => $cantidadDesdeCedis
                    ];
                }

                if ($cantidadDesdeSucursal > 0) {
                    $productosSucursal[] = [
                        'clave' => $producto->clave,
                        'cantidad' => $cantidadDesdeSucursal
                    ];
                }

                $detalleDistribucion[] = [
                    'clave' => $producto->clave,
                    'cantidad_solicitada' => $cantidadSolicitada,
                    'desde_cedis' => $cantidadDesdeCedis,
                    'desde_sucursal' => $cantidadDesdeSucursal,
                    'origen' => 'Ambos (distribuido)'
                ];
            }
        }

        $envios = [];
        $totales = [
            'cajas' => 0,
            'subtotal' => 0,
            'iva' => 0,
            'monto_total' => 0
        ];

        // Cotizar envío desde CEDIS
        if (!empty($productosCedis)) {
            $respuesta = $this->repositoryCva->cotizarPedido([
                'paqueteria' => $this->PAQUETERIAID,
                'cp' => $data->cp,
                'cp_sucursal' => $this->CP_CEDIS_GDL,
                'productos' => $productosCedis
            ]);

            $cot = $respuesta['cotizacion'];
            
            $envios['cedis'] = [
                'origen' => 'CEDIS Guadalajara',
                'cp_origen' => $this->CP_CEDIS_GDL,
                'productos' => $productosCedis,
                'cantidad_productos' => count($productosCedis),
                'cajas' => $cot['cajas'],
                'subtotal' => $cot['subtotal'],
                'iva' => $cot['iva'],
                'total' => $cot['montoTotal']
            ];

            $totales['cajas'] += $cot['cajas'];
            $totales['subtotal'] += $cot['subtotal'];
            $totales['iva'] += $cot['iva'];
            $totales['monto_total'] += $cot['montoTotal'];
        }

        // Cotizar envío desde Sucursal
        if (!empty($productosSucursal)) {
            $respuesta = $this->repositoryCva->cotizarPedido([
                'paqueteria' => $this->PAQUETERIAID,
                'cp' => $data->cp,
                'cp_sucursal' => $this->CP_SUCURSAL_GDL,
                'productos' => $productosSucursal
            ]);

            $cot = $respuesta['cotizacion'];
            
            $envios['sucursal'] = [
                'origen' => 'Sucursal Guadalajara',
                'cp_origen' => $this->CP_SUCURSAL_GDL,
                'productos' => $productosSucursal,
                'cantidad_productos' => count($productosSucursal),
                'cajas' => $cot['cajas'],
                'subtotal' => $cot['subtotal'],
                'iva' => $cot['iva'],
                'total' => $cot['montoTotal']
            ];

            $totales['cajas'] += $cot['cajas'];
            $totales['subtotal'] += $cot['subtotal'];
            $totales['iva'] += $cot['iva'];
            $totales['monto_total'] += $cot['montoTotal'];
        }

        return new ApiResponseData(
            success: true,
            message: count($envios) > 1 
                ? "Se requieren " . count($envios) . " envíos desde diferentes ubicaciones"
                : "Cotización realizada desde un solo origen",
            data: [
                'envios' => $envios,
                'totales' => [
                    'cajas' => $totales['cajas'],
                    'subtotal' => round($totales['subtotal'], 2),
                    'iva' => round($totales['iva'], 2),
                    'monto_total' => round($totales['monto_total'], 2)
                ],
                'distribucion_productos' => $detalleDistribucion,
                'requiere_envios_multiples' => count($envios) > 1
            ]
        );
    }
}
