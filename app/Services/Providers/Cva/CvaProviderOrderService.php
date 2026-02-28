<?php

namespace App\Services\Providers\Cva;

use App\Contratos\ProveedorServiceInterface;
use App\Data\Pedidos\CotizacionEnvioData;
use App\Data\Pedidos\PedidoProveedorRequestData;
use App\Data\Pedidos\ProductoEnriquecidoData;
use App\Exceptions\Cva\CvaApiException;
use App\Exceptions\Cva\CvaStockException;
use App\Exceptions\Orders\ShippingException;
use App\Exceptions\Orders\ShippingOutOfRangeException;
use App\Exceptions\Orders\ShippingQuoteException;
use App\Models\Cliente;
use App\Models\Proveedor;
use App\Models\ProveedorEstado;
use App\Models\ProveedorProducto;
use App\Repository\CvaRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CvaProviderOrderService implements ProveedorServiceInterface
{
    private int $CLAVE_CEDIS_GDL    = 46;
    private int $CLAVE_SUCURSAL_GDL = 1;
    private int $PAQUETERIAID       = 4;
    private int $CP_CEDIS_GDL       = 45640;
    private int $CP_SUCURSAL_GDL    = 44900;

    public function __construct(
        private CvaRepository $cvaRepository
    ) {}

    // =========================================================================
    // ENRIQUECIMIENTO
    // =========================================================================

    /**
     * Enriquecer productos con precios, ofertas y metadata de CVA.
     *
     * CAMBIOS ESQUEMA NUEVO:
     * - precio_oferta eliminado → usar precio_con_descuento_mxn ?? precio_con_descuento
     * - La promoción activa se detecta por es_oferta = true y precio_con_descuento > 0
     * - disponible_en_promocion sigue siendo el campo de stock de promo
     */
    public function enriquecerProductos(array $productosBasicos): array
    {
        $claves = collect($productosBasicos)->pluck('codigo_proveedor')->unique()->toArray();

        $productosDb = ProveedorProducto::whereIn('codigo_proveedor', $claves)
            ->with(['pricio', 'promociones' => function ($query) {
                $query->where('es_oferta', true)
                      // Precio válido: primero MXN convertido, si no el original
                      ->where(function ($q) {
                          $q->where(function ($q2) {
                              $q2->whereNotNull('precio_con_descuento_mxn')
                                 ->where('precio_con_descuento_mxn', '>', 0);
                          })->orWhere(function ($q2) {
                              $q2->whereNull('precio_con_descuento_mxn')
                                 ->whereNotNull('precio_con_descuento')
                                 ->where('precio_con_descuento', '>', 0);
                          });
                      })
                      ->where('disponible_en_promocion', '>', 0);
            }])
            ->get()
            ->keyBy('codigo_proveedor');

        return collect($productosBasicos)->map(function ($productoBasico) use ($productosDb) {
            $proveedorProducto = $productosDb->get($productoBasico['codigo_proveedor']);

            if (!$proveedorProducto) {
                throw new \InvalidArgumentException(
                    "Producto {$productoBasico['codigo_proveedor']} no encontrado en CVA"
                );
            }

            $cantidadSolicitada = $productoBasico['cantidad'];

            // Buscar la promoción activa con mejor precio y stock suficiente
            $promocionAplicable = $proveedorProducto->promociones
                ->filter(fn($p) => $p->disponible_en_promocion >= $cantidadSolicitada)
                ->sortBy(fn($p) => $this->precioEfectivoPromocion($p))
                ->first();

            if (!$promocionAplicable) {
                $promocionesInsuficientes = $proveedorProducto->promociones
                    ->filter(fn($p) => $p->disponible_en_promocion > 0
                                    && $p->disponible_en_promocion < $cantidadSolicitada);

                if ($promocionesInsuficientes->isNotEmpty()) {
                    Log::info('Promoción no aplicada por stock insuficiente', [
                        'codigo_proveedor'    => $productoBasico['codigo_proveedor'],
                        'cantidad_solicitada' => $cantidadSolicitada,
                        'promociones'         => $promocionesInsuficientes->map(fn($p) => [
                            'clave'                  => $p->clave_promocion,
                            'stock_promo'            => $p->disponible_en_promocion,
                            'precio_con_descuento'   => $p->precio_con_descuento,
                            'precio_con_descuento_mxn' => $p->precio_con_descuento_mxn,
                            'moneda_original'        => $p->moneda_precio_original,
                        ])->toArray(),
                    ]);
                }
            }

            return ProductoEnriquecidoData::fromProveedorProducto(
                $proveedorProducto,
                $cantidadSolicitada
            );

        })->toArray();
    }

    // =========================================================================
    // VALIDACIÓN DE DISPONIBILIDAD
    // =========================================================================

    /**
     * Valida stock físico + stock de promoción si aplica.
     * El stock físico sigue viniendo de proveedor_productos.stock / stock_cd
     * (campos resumen que se mantienen aunque ahora también exista la tabla de almacenes).
     */
    public function validarDisponibilidad(array $productos): bool
    {
        foreach ($productos as $producto) {
            $metadata      = $producto->metadataProveedor;
            $stockCedis    = $metadata['stock_cd'] ?? 0;
            $stockSucursal = $metadata['stock']    ?? 0;
            $stockTotal    = $stockCedis + $stockSucursal;

            if ($stockTotal < $producto->cantidad) {
                throw new CvaStockException(
                    "Stock insuficiente para {$producto->codigoProveedor}. " .
                    "Solicitado: {$producto->cantidad}, " .
                    "Disponible: {$stockTotal} (CEDIS: {$stockCedis}, Sucursal: {$stockSucursal})",
                    400
                );
            }

            if ($producto->enOferta && $producto->clavePromocion) {
                $stockPromocion = $this->obtenerStockPromocion(
                    $producto->codigoProveedor,
                    $producto->clavePromocion
                );

                if ($stockPromocion !== null && $stockPromocion < $producto->cantidad) {
                    throw new CvaStockException(
                        "Stock insuficiente en promoción para {$producto->codigoProveedor}. " .
                        "Solicitado: {$producto->cantidad}, " .
                        "Disponible en promoción: {$stockPromocion}. " .
                        "La promoción '{$producto->clavePromocion}' tiene unidades limitadas.",
                        400
                    );
                }
            }
        }

        return true;
    }

    // =========================================================================
    // PEDIDOS
    // =========================================================================

    public function crearPedido(PedidoProveedorRequestData $request, Cliente $cliente): array
    {
        try {
            $this->validarAlcanceDeEnvio($cliente);

            $productosEnriquecidos = $this->enriquecerProductos($request->productos);
            $this->validarDisponibilidad($productosEnriquecidos);

            $distribucion    = $this->distribucionStock($productosEnriquecidos);
            $costoEnvioData  = $this->calcularCostoEnvio($distribucion, $cliente);

            if (!$costoEnvioData['success']) {
                return ['success' => false, 'error' => 'Error al calcular costo de envío'];
            }

            return DB::transaction(function () use ($request, $cliente, $distribucion, $costoEnvioData) {
                $envios     = $costoEnvioData['data']['envios'];
                $respuestas = [];

                $payloadBase = [
                    'test'          => $request->test ?? true,
                    'num_oc'        => $request->numeroOrden,
                    'observaciones' => $request->observaciones ?? '',
                    'tipo_flete'    => 'FF',
                    'cotiza_flete'  => $request->cotiza_flete,
                    'flete'         => $this->formatearDatosEnvio($cliente, $request->datosEnvio),
                ];

                if (!empty($distribucion['productos_cedis'])) {
                    $this->descontarStockLocal($distribucion['productos_cedis'], 'stock_cd');

                    $response = $this->cvaRepository->crearOrden(array_merge($payloadBase, [
                        'codigo_sucursal' => $this->CLAVE_CEDIS_GDL,
                        'productos'       => $distribucion['productos_cedis'],
                    ]));

                    $respuestas[] = $this->mapearRespuestaOrden($response, $envios['cedis'] ?? [], 'CEDIS');
                }

                if (!empty($distribucion['productos_sucursal'])) {
                    $this->descontarStockLocal($distribucion['productos_sucursal'], 'stock');

                    $response = $this->cvaRepository->crearOrden(array_merge($payloadBase, [
                        'codigo_sucursal' => $this->CLAVE_SUCURSAL_GDL,
                        'productos'       => $distribucion['productos_sucursal'],
                    ]));

                    $respuestas[] = $this->mapearRespuestaOrden($response, $envios['sucursal'] ?? [], 'Sucursal');
                }

                return [
                    'success'  => true,
                    'data'     => $respuestas,
                    'metadata' => [
                        'requiere_envios_multiples' => $distribucion['requiere_envios_multiples'],
                        'total_envios'              => $costoEnvioData['data']['totales'],
                        'distribucion'              => $distribucion['distribucion_productos'],
                    ],
                ];
            });

        } catch (CvaStockException $e) {
            Log::warning('Stock insuficiente en CVA', ['request' => $request, 'error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];

        } catch (CvaApiException | \Exception $e) {
            Log::error('Error al crear pedido CVA', [
                'request' => $request,
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            return ['success' => false, 'error' => 'Error al procesar pedido con CVA: ' . $e->getMessage()];
        }
    }

    public function cotizarEnvio(array $productos, Cliente $cliente): CotizacionEnvioData
    {
        $this->validarAlcanceDeEnvio($cliente);

        try {
            $distribucion = $this->distribucionStock($productos);
            $resultado    = $this->calcularCostoEnvio($distribucion, $cliente);

            if (!$resultado['success']) {
                throw new ShippingQuoteException('CVA', $resultado['message'] ?? 'Error desconocido', $productos);
            }

            $totales = $resultado['data']['totales'];

            return new CotizacionEnvioData(
                proveedorId: $productos[0]->proveedorId,
                subtotal:    $totales['subtotal'],
                iva:         $totales['iva'],
                montoTotal:  $totales['monto_total'],
                detalles:    [
                    'envios'                    => $resultado['data']['envios'],
                    'requiere_envios_multiples' => $resultado['data']['requiere_envios_multiples'],
                    'distribucion'              => $resultado['data']['distribucion'],
                ]
            );

        } catch (ShippingOutOfRangeException | ShippingQuoteException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error al cotizar envío CVA', [
                'productos'  => collect($productos)->map->codigoProveedor->toArray(),
                'cliente_id' => $cliente->id,
                'error'      => $e->getMessage(),
            ]);
            throw new ShippingQuoteException('CVA', 'Error inesperado: ' . $e->getMessage(), $productos);
        }
    }

    // =========================================================================
    // HELPERS PRIVADOS — STOCK Y PROMOCIONES
    // =========================================================================

    /**
     * Devuelve el precio efectivo de una promoción en MXN para comparación.
     * Prioriza precio_con_descuento_mxn (ya convertido) sobre precio_con_descuento.
     */
    private function precioEfectivoPromocion($promocion): float
    {
        return (float) ($promocion->precio_con_descuento_mxn
            ?? $promocion->precio_con_descuento
            ?? PHP_FLOAT_MAX);
    }

    /**
     * Obtiene el stock disponible de una promoción específica.
     */
    private function obtenerStockPromocion(string $codigoProveedor, string $clavePromocion): ?int
    {
        try {
            $pp = ProveedorProducto::where('codigo_proveedor', $codigoProveedor)->first();

            if (!$pp) return null;

            $promocion = $pp->promociones()
                ->where('es_oferta', true)
                ->where('clave_promocion', $clavePromocion)
                ->first();

            return $promocion?->disponible_en_promocion;

        } catch (\Exception $e) {
            Log::error('Error al obtener stock de promoción', [
                'codigo_proveedor' => $codigoProveedor,
                'clave_promocion'  => $clavePromocion,
                'error'            => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Descuenta stock físico y stock de promoción si aplica.
     */
    private function descontarStockLocal(array $productos, string $campoStock): void
    {
        foreach ($productos as $producto) {
            $actualizado = DB::table('proveedor_productos')
                ->where('codigo_proveedor', $producto['clave'])
                ->where($campoStock, '>=', $producto['cantidad'])
                ->decrement($campoStock, $producto['cantidad']);

            if (!$actualizado) {
                throw new CvaStockException(
                    "No se pudo descontar stock de {$producto['clave']}. " .
                    "Posible inconsistencia en stock disponible.",
                    500
                );
            }

            $this->descontarStockPromocion($producto['clave'], $producto['cantidad']);
        }

        Log::info('Stock descontado localmente', [
            'productos'    => collect($productos)->pluck('clave')->toArray(),
            'campo_stock'  => $campoStock,
        ]);
    }

    /**
     * Descuenta disponible_en_promocion de la mejor promoción activa.
     *
     * CAMBIOS ESQUEMA NUEVO:
     * - Busca por precio_con_descuento_mxn o precio_con_descuento en lugar de precio_oferta
     * - Ordena por precio efectivo MXN para aplicar la más ventajosa
     */
    private function descontarStockPromocion(string $codigoProveedor, int $cantidad): void
    {
        try {
            $pp = ProveedorProducto::where('codigo_proveedor', $codigoProveedor)->first();
            if (!$pp) return;

            // La mejor promoción activa con stock suficiente
            $promocionActiva = $pp->promociones()
                ->where('es_oferta', true)
                ->where(function ($q) {
                    $q->where(function ($q2) {
                        $q2->whereNotNull('precio_con_descuento_mxn')
                           ->where('precio_con_descuento_mxn', '>', 0);
                    })->orWhere(function ($q2) {
                        $q2->whereNull('precio_con_descuento_mxn')
                           ->whereNotNull('precio_con_descuento')
                           ->where('precio_con_descuento', '>', 0);
                    });
                })
                ->where('disponible_en_promocion', '>=', $cantidad)
                ->orderByRaw('COALESCE(precio_con_descuento_mxn, precio_con_descuento) ASC')
                ->first();

            if (!$promocionActiva) return;

            $actualizado = DB::table('proveedor_producto_promociones')
                ->where('id', $promocionActiva->id)
                ->where('disponible_en_promocion', '>=', $cantidad)
                ->decrement('disponible_en_promocion', $cantidad);

            if ($actualizado) {
                Log::info('Stock de promoción descontado', [
                    'codigo_proveedor'       => $codigoProveedor,
                    'promocion_id'           => $promocionActiva->id,
                    'clave_promocion'        => $promocionActiva->clave_promocion,
                    'cantidad'               => $cantidad,
                    'precio_con_descuento'   => $promocionActiva->precio_con_descuento,
                    'precio_con_descuento_mxn' => $promocionActiva->precio_con_descuento_mxn,
                    'moneda_original'        => $promocionActiva->moneda_precio_original,
                ]);
            } else {
                Log::warning('No se pudo descontar stock de promoción', [
                    'codigo_proveedor'      => $codigoProveedor,
                    'promocion_id'          => $promocionActiva->id,
                    'cantidad_solicitada'   => $cantidad,
                    'disponible_en_promocion' => $promocionActiva->disponible_en_promocion,
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Error al descontar stock de promoción', [
                'codigo_proveedor' => $codigoProveedor,
                'cantidad'         => $cantidad,
                'error'            => $e->getMessage(),
            ]);
        }
    }

    // =========================================================================
    // HELPERS PRIVADOS — DISTRIBUCIÓN Y ENVÍO
    // =========================================================================

    private function distribucionStock(array $productos): array
    {
        $productosCedis      = [];
        $productosSucursal   = [];
        $detalleDistribucion = [];
        $requiereMultiples   = false;

        foreach ($productos as $producto) {
            $metadata      = $producto->metadataProveedor;
            $stockCedis    = $metadata['stock_cd'] ?? 0;
            $stockSucursal = $metadata['stock']    ?? 0;

            if ($stockCedis >= $producto->cantidad) {
                $productosCedis[]    = ['clave' => $producto->codigoProveedor, 'cantidad' => $producto->cantidad];
                $detalleDistribucion[] = $this->detalleDistribucion($producto, $producto->cantidad, 0, 'CEDIS');

            } elseif ($stockSucursal >= $producto->cantidad) {
                $productosSucursal[] = ['clave' => $producto->codigoProveedor, 'cantidad' => $producto->cantidad];
                $detalleDistribucion[] = $this->detalleDistribucion($producto, 0, $producto->cantidad, 'Sucursal');

            } else {
                $desdeCedis    = $stockCedis;
                $desdeSucursal = $producto->cantidad - $stockCedis;

                if ($desdeCedis > 0) {
                    $productosCedis[]  = ['clave' => $producto->codigoProveedor, 'cantidad' => $desdeCedis];
                }
                if ($desdeSucursal > 0) {
                    $productosSucursal[] = ['clave' => $producto->codigoProveedor, 'cantidad' => $desdeSucursal];
                }

                $detalleDistribucion[] = $this->detalleDistribucion($producto, $desdeCedis, $desdeSucursal, 'Distribuido');
                $requiereMultiples     = true;
            }
        }

        return [
            'productos_cedis'          => $productosCedis,
            'productos_sucursal'       => $productosSucursal,
            'distribucion_productos'   => $detalleDistribucion,
            'requiere_envios_multiples' => $requiereMultiples,
        ];
    }

    private function detalleDistribucion($producto, int $desdeCedis, int $desdeSucursal, string $origen): array
    {
        return [
            'proveedor_producto_id' => $producto->proveedorProductoId,
            'clave'                 => $producto->codigoProveedor,
            'cantidad_solicitada'   => $producto->cantidad,
            'desde_cedis'           => $desdeCedis,
            'desde_sucursal'        => $desdeSucursal,
            'origen'                => $origen,
        ];
    }

    private function calcularCostoEnvio(array $distribucion, Cliente $cliente): array
    {
        $totales = ['subtotal' => 0, 'iva' => 0, 'monto_total' => 0];
        $envios  = [];

        try {
            foreach ([
                'cedis'    => [$distribucion['productos_cedis'],    $this->CP_CEDIS_GDL,    'CEDIS Guadalajara'],
                'sucursal' => [$distribucion['productos_sucursal'], $this->CP_SUCURSAL_GDL, 'Sucursal Guadalajara'],
            ] as $origen => [$productos, $cp, $label]) {
                if (empty($productos)) continue;

                $respuesta = $this->cvaRepository->cotizarPedido([
                    'paqueteria'   => $this->PAQUETERIAID,
                    'cp'           => $cliente->codigo_postal,
                    'cp_sucursal'  => $cp,
                    'productos'    => $productos,
                ]);

                if (isset($respuesta['cotizacion'])) {
                    $cot           = $respuesta['cotizacion'];
                    $envios[$origen] = [
                        'origen'             => $label,
                        'productos'          => collect($productos)->pluck('clave')->toArray(),
                        'cantidad_productos' => collect($productos)->sum('cantidad'),
                        'subtotal'           => $cot['subtotal']    ?? 0,
                        'iva'                => $cot['iva']         ?? 0,
                        'monto_total'        => $cot['montoTotal']  ?? 0,
                    ];

                    $totales['subtotal']    += $cot['subtotal']   ?? 0;
                    $totales['iva']         += $cot['iva']        ?? 0;
                    $totales['monto_total'] += $cot['montoTotal'] ?? 0;
                }
            }

            return [
                'success' => true,
                'data'    => [
                    'envios'                    => $envios,
                    'totales'                   => array_map(fn($v) => round($v, 2), $totales),
                    'requiere_envios_multiples' => $distribucion['requiere_envios_multiples'],
                    'distribucion'              => $distribucion['distribucion_productos'],
                ],
            ];

        } catch (\Exception $e) {
            Log::error('Error al calcular costo de envío CVA', [
                'cliente_id' => $cliente->id,
                'error'      => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'Error al calcular costo de envío: ' . $e->getMessage()];
        }
    }

    private function mapearRespuestaOrden(array $response, array $flete, string $origen): array
    {
        return [
            'folioPedido'  => $response['pedido'],
            'subtotal'     => $response['subtotal'],
            'iva'          => $response['iva']           ?? 0,
            'total'        => $response['total'],
            'moneda'       => $response['moneda']        ?? 'MXN',
            'emailAgente'  => $response['email_agente']  ?? null,
            'emailAlmacen' => $response['email_almacen'] ?? null,
            'flete'        => $flete,
            'origen'       => $origen,
        ];
    }

    // =========================================================================
    // HELPERS PRIVADOS — ENVÍO Y VALIDACIONES
    // =========================================================================

    private function validarAlcanceDeEnvio(Cliente $cliente): bool
    {
        try {
            $estado = ProveedorEstado::where('descripcion', strtoupper(trim($cliente->estado)))->first();

            if (!$estado) {
                throw new ShippingOutOfRangeException($cliente->estado, $cliente->ciudad, 'CVA');
            }

            $ciudadExiste = $estado->ciudades()
                ->where('descripcion', strtoupper(trim($cliente->ciudad)))
                ->exists();

            if (!$ciudadExiste) {
                throw new ShippingOutOfRangeException($cliente->estado, $cliente->ciudad, 'CVA');
            }

            return true;

        } catch (ShippingOutOfRangeException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error al validar alcance de envío', ['cliente_id' => $cliente->id, 'error' => $e->getMessage()]);
            throw new ShippingException('Error al validar alcance de envío', [
                'cliente_id'     => $cliente->id,
                'error_original' => $e->getMessage(),
            ]);
        }
    }

    private function formatearDatosEnvio(Cliente $cliente, ?array $datosEnvio): array
    {
        $estado = ProveedorEstado::where('descripcion', strtoupper(trim($cliente->estado)))->first();
        $ciudad = $estado->ciudades()->where('descripcion', strtoupper(trim($cliente->ciudad)))->first();

        $flete = [
            'calle'            => $cliente->calle              ?? '',
            'numero'           => $cliente->numero_exterior    ?? '',
            'numero_interior'  => $cliente->numero_interior    ?? '',
            'cp'               => $cliente->codigo_postal      ?? '',
            'estado'           => (int) $estado->clave,
            'ciudad'           => (int) $ciudad->clave,
            'paqueteria'       => $this->PAQUETERIAID,
            'atencion'         => $cliente->nombre             ?? '',
            'colonia'          => $cliente->colonia            ?? '',
        ];

        return $datosEnvio ? array_merge($flete, $datosEnvio) : $flete;
    }

    // =========================================================================
    // INTERFAZ
    // =========================================================================

    public function soporta(int $proveedorId): bool
    {
        return Proveedor::where('codigo_proveedor', 'cva')->where('id', $proveedorId)->exists();
    }

    public function obtenerEstatus(string $folioPedido): string
    {
        return 'pendiente'; // TODO: implementar
    }

    public function cancelarPedido(string $folioPedido): bool
    {
        try {
            Log::info('Solicitando cancelación de pedido CVA', ['folio' => $folioPedido]);
            return true; // TODO: implementar
        } catch (\Exception $e) {
            Log::error('Error al cancelar pedido CVA', ['folio' => $folioPedido, 'error' => $e->getMessage()]);
            return false;
        }
    }

    public function obtenerNombre(): string
    {
        return 'cva';
    }
}