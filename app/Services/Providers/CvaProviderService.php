<?php

namespace App\Services\Providers;

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

class CvaProviderService implements ProveedorServiceInterface
{
    private int $CLAVE_CEDIS_GDL = 46;
    private int $CLAVE_SUCURSAL_GDL = 1;
    private int $PAQUETERIAID = 4; // Paquetexpress
    private int $CP_CEDIS_GDL = 45640;  
    private int $CP_SUCURSAL_GDL = 44900;

    public function __construct(
        private CvaRepository $cvaRepository
    ) {}

    /**
     * Enriquecer productos con precios, ofertas y metadata de CVA
     * IMPORTANTE: Solo aplica ofertas si hay stock disponible en promoción
     * 
     * @param array $productosBasicos [['codigo_proveedor' => 'XX', 'cantidad' => 2], ...]
     * @return ProductoEnriquecidoData[]
     */
    public function enriquecerProductos(array $productosBasicos): array
    {
        $claves = collect($productosBasicos)->pluck('codigo_proveedor')->unique()->toArray();

        $productosDb = ProveedorProducto::whereIn('codigo_proveedor', $claves)
            ->with(['pricio', 'promociones' => function($query) {
                $query->where('es_oferta', true)
                      ->whereNotNull('precio_oferta')
                      ->where('precio_oferta', '>', 0)
                      ->where('disponible_en_promocion', '>', 0); // Solo promociones con stock
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

            // Obtener cantidad solicitada
            $cantidadSolicitada = $productoBasico['cantidad'];

            // Buscar promoción activa con stock suficiente
            $promocionAplicable = $proveedorProducto->promociones
                ->where('disponible_en_promocion', '>=', $cantidadSolicitada)
                ->sortBy('precio_oferta')
                ->first();

            // Si no hay promoción con stock suficiente, usar precio regular
            if (!$promocionAplicable) {
                // Verificar si había promociones pero sin stock suficiente (para logging)
                $promocionesInsuficientes = $proveedorProducto->promociones
                    ->where('disponible_en_promocion', '<', $cantidadSolicitada)
                    ->where('disponible_en_promocion', '>', 0);

                if ($promocionesInsuficientes->isNotEmpty()) {
                    \Log::info('Promoción no aplicada por stock insuficiente', [
                        'codigo_proveedor' => $productoBasico['codigo_proveedor'],
                        'cantidad_solicitada' => $cantidadSolicitada,
                        'promociones_disponibles' => $promocionesInsuficientes->map(fn($p) => [
                            'clave' => $p->clave_promocion,
                            'stock_promo' => $p->disponible_en_promocion,
                            'precio_oferta' => $p->precio_oferta
                        ])->toArray()
                    ]);
                }
            }

            return ProductoEnriquecidoData::fromProveedorProducto(
                $proveedorProducto,
                $cantidadSolicitada
            );

        })->toArray();
    }

    /**
     * Validar disponibilidad de stock según reglas de CVA
     * CVA tiene CEDIS + Sucursal, se valida stock total
     * IMPORTANTE: También valida stock de promoción si aplica
     * 
     * @param ProductoEnriquecidoData[] $productos
     * @throws CvaStockException
     */
    public function validarDisponibilidad(array $productos): bool
    {
        foreach ($productos as $producto) {
            $metadata = $producto->metadataProveedor;
            
            $stockCedis = $metadata['stock_cd'] ?? 0;
            $stockSucursal = $metadata['stock'] ?? 0;
            $stockTotal = $stockCedis + $stockSucursal;

            // 1. Validar stock físico total
            if ($stockTotal < $producto->cantidad) {
                throw new CvaStockException(
                    "Stock insuficiente para {$producto->codigoProveedor}. " .
                    "Solicitado: {$producto->cantidad}, " .
                    "Disponible: {$stockTotal} (CEDIS: {$stockCedis}, Sucursal: {$stockSucursal})",
                    400
                );
            }

            // 2. Si está en oferta, validar stock de promoción
            if ($producto->enOferta && $producto->clavePromocion) {
                $stockPromocion = $this->obtenerStockPromocion($producto->codigoProveedor, $producto->clavePromocion);
                
                if ($stockPromocion !== null && $stockPromocion < $producto->cantidad) {
                    throw new CvaStockException(
                        "Stock insuficiente en promoción para {$producto->codigoProveedor}. " .
                        "Solicitado: {$producto->cantidad}, " .
                        "Disponible en promoción: {$stockPromocion}. " .
                        "Stock físico disponible: {$stockTotal}, " .
                        "pero la promoción '{$producto->clavePromocion}' tiene unidades limitadas.",
                        400
                    );
                }
            }
        }

        return true;
    }

    /**
     * Obtener stock disponible en promoción
     * 
     * @param string $codigoProveedor
     * @param string $clavePromocion
     * @return int|null
     */
    private function obtenerStockPromocion(string $codigoProveedor, string $clavePromocion): ?int
    {
        try {
            $proveedorProducto = ProveedorProducto::where('codigo_proveedor', $codigoProveedor)
                ->first();

            if (!$proveedorProducto) {
                return null;
            }

            $promocion = $proveedorProducto->promociones()
                ->where('es_oferta', true)
                ->where('clave_promocion', $clavePromocion)
                ->first();

            return $promocion ? $promocion->disponible_en_promocion : null;

        } catch (\Exception $e) {
            \Log::error('Error al obtener stock de promoción', [
                'codigo_proveedor' => $codigoProveedor,
                'clave_promocion' => $clavePromocion,
                'error' => $e->getMessage()
            ]);
            
            return null;
        }
    }

    /**
     * Cotizar envío sin crear pedido
     * CVA puede requerir múltiples envíos (CEDIS + Sucursal)
     * 
     * @param ProductoEnriquecidoData[] $productos
     * @throws ShippingOutOfRangeException
     * @throws ShippingQuoteException
     */
    public function cotizarEnvio(array $productos, Cliente $cliente): CotizacionEnvioData
    {
        // Validar alcance primero
        $this->validarAlcanceDeEnvio($cliente);

        try {
            // Obtener distribución de stock (lógica interna de CVA)
            $distribucion = $this->distribucionStock($productos);
            
            // Calcular costos
            $resultado = $this->calcularCostoEnvio($distribucion, $cliente);
            
            if (!$resultado['success']) {
                throw new ShippingQuoteException(
                    'CVA',
                    $resultado['message'] ?? 'Error desconocido al cotizar',
                    $productos
                );
            }

            $totales = $resultado['data']['totales'];
            
            return new CotizacionEnvioData(
                proveedorId: $productos[0]->proveedorId,
                subtotal: $totales['subtotal'],
                iva: $totales['iva'],
                montoTotal: $totales['monto_total'],
                detalles: [
                    'envios' => $resultado['data']['envios'],
                    'requiere_envios_multiples' => $resultado['data']['requiere_envios_multiples'],
                    'distribucion' => $resultado['data']['distribucion']
                ]
            );

        } catch (ShippingOutOfRangeException | ShippingQuoteException $e) {
            throw $e;
        } catch (\Exception $e) {
            \Log::error('Error al cotizar envío CVA', [
                'productos' => collect($productos)->map->codigoProveedor->toArray(),
                'cliente_id' => $cliente->id,
                'error' => $e->getMessage()
            ]);

            throw new ShippingQuoteException(
                'CVA',
                'Error inesperado: ' . $e->getMessage(),
                $productos
            );
        }
    }

    /**
     * Crear pedido con CVA
     */
    public function crearPedido(PedidoProveedorRequestData $request, Cliente $cliente): array
    {
        try {
            // Validaciones previas
            $this->validarAlcanceDeEnvio($cliente);
            
            // Enriquecer productos para obtener metadata de stock
            $productosEnriquecidos = $this->enriquecerProductos($request->productos);
            
            // Validar disponibilidad
            $this->validarDisponibilidad($productosEnriquecidos);
            
            // Obtener distribución
            $distribucion = $this->distribucionStock($productosEnriquecidos);
            
            // Calcular costo de envío
            $costoEnvioData = $this->calcularCostoEnvio($distribucion, $cliente);

            if (!$costoEnvioData['success']) {
                return [
                    'success' => false,
                    'error' => 'Error al calcular costo de envío'
                ];
            }

            // TRANSACCIÓN: Descontar primero, crear después
            return \DB::transaction(function () use ($request, $cliente, $distribucion, $costoEnvioData) {
                $envios = $costoEnvioData['data']['envios'];
                $respuestas = [];

                $payloadBase = [
                    'test' => $request->test ?? true,
                    'num_oc' => $request->numeroOrden,
                    'observaciones' => $request->observaciones ?? '',
                    'tipo_flete' => 'FF',
                    'cotiza_flete' => $request->cotiza_flete,
                    'flete' => $this->formatearDatosEnvio($cliente, $request->datosEnvio)
                ];

                // PEDIDO DESDE CEDIS
                if (!empty($distribucion['productos_cedis'])) {
                    $this->descontarStockLocal($distribucion['productos_cedis'], 'stock_cd');

                    $payload = array_merge($payloadBase, [
                        'codigo_sucursal' => $this->CLAVE_CEDIS_GDL,
                        'productos' => $distribucion['productos_cedis']
                    ]);

                    $response = $this->cvaRepository->crearOrden($payload);

                    $respuestas[] = [
                        'folioPedido' => $response['pedido'],
                        'subtotal' => $response['subtotal'],
                        'iva' => $response['iva'] ?? 0,
                        'total' => $response['total'],
                        'moneda' => $response['moneda'] ?? 'MXN',
                        'emailAgente' => $response['email_agente'] ?? null,
                        'emailAlmacen' => $response['email_almacen'] ?? null,
                        'flete' => $envios['cedis'] ?? [],
                        'origen' => 'CEDIS'
                    ];
                }

                // PEDIDO DESDE SUCURSAL
                if (!empty($distribucion['productos_sucursal'])) {
                    $this->descontarStockLocal($distribucion['productos_sucursal'], 'stock');

                    $payload = array_merge($payloadBase, [
                        'codigo_sucursal' => $this->CLAVE_SUCURSAL_GDL,
                        'productos' => $distribucion['productos_sucursal']
                    ]);

                    $response = $this->cvaRepository->crearOrden($payload);

                    $respuestas[] = [
                        'folioPedido' => $response['pedido'],
                        'subtotal' => $response['subtotal'],
                        'iva' => $response['iva'] ?? 0,
                        'total' => $response['total'],
                        'moneda' => $response['moneda'] ?? 'MXN',
                        'emailAgente' => $response['email_agente'] ?? null,
                        'emailAlmacen' => $response['email_almacen'] ?? null,
                        'flete' => $envios['sucursal'] ?? [],
                        'origen' => 'Sucursal'
                    ];
                }

                return [
                    'success' => true,
                    'data' => $respuestas,
                    'metadata' => [
                        'requiere_envios_multiples' => $distribucion['requiere_envios_multiples'],
                        'total_envios' => $costoEnvioData['data']['totales'],
                        'distribucion' => $distribucion['distribucion_productos']
                    ]
                ];
            });

        } catch (CvaStockException $e) {
            \Log::warning('Stock insuficiente en CVA', [
                'request' => $request,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];

        } catch (CvaApiException $e) {
            \Log::error('Error al crear pedido CVA', [
                'request' => $request,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => 'Error al procesar pedido con CVA: ' . $e->getMessage()
            ];
        }
         catch (\Exception $e) {
            \Log::error('Error al crear pedido CVA', [
                'request' => $request,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => 'Error al procesar pedido con CVA: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Distribuir productos entre CEDIS y Sucursal según stock disponible
     * LÓGICA INTERNA DE CVA - El orquestador no debe conocer esto
     * 
     * @param ProductoEnriquecidoData[] $productos
     */
    private function distribucionStock(array $productos): array
    {
        $productosCedis = [];
        $productosSucursal = [];
        $detalleDistribucion = [];
        $requiereEnviosMultiples = false;

        foreach ($productos as $producto) {
            $metadata = $producto->metadataProveedor;
            $stockCedis = $metadata['stock_cd'] ?? 0;
            $stockSucursal = $metadata['stock'] ?? 0;

            // Caso 1: Todo desde CEDIS
            if ($stockCedis >= $producto->cantidad) {
                $productosCedis[] = [
                    'clave' => $producto->codigoProveedor,
                    'cantidad' => $producto->cantidad
                ];

                $detalleDistribucion[] = [
                    'proveedor_producto_id' => $producto->proveedorProductoId,
                    'clave' => $producto->codigoProveedor,
                    'cantidad_solicitada' => $producto->cantidad,
                    'desde_cedis' => $producto->cantidad,
                    'desde_sucursal' => 0,
                    'origen' => 'CEDIS'
                ];
            }
            // Caso 2: Todo desde Sucursal
            elseif ($stockSucursal >= $producto->cantidad) {
                $productosSucursal[] = [
                    'clave' => $producto->codigoProveedor,
                    'cantidad' => $producto->cantidad
                ];

                $detalleDistribucion[] = [
                    'proveedor_producto_id' => $producto->proveedorProductoId,
                    'clave' => $producto->codigoProveedor,
                    'cantidad_solicitada' => $producto->cantidad,
                    'desde_cedis' => 0,
                    'desde_sucursal' => $producto->cantidad,
                    'origen' => 'Sucursal'
                ];
            }
            // Caso 3: Dividir entre ambos
            else {
                $cantidadDesdeCedis = $stockCedis;
                $cantidadDesdeSucursal = $producto->cantidad - $stockCedis;

                if ($cantidadDesdeCedis > 0) {
                    $productosCedis[] = [
                        'clave' => $producto->codigoProveedor,
                        'cantidad' => $cantidadDesdeCedis
                    ];
                }

                if ($cantidadDesdeSucursal > 0) {
                    $productosSucursal[] = [
                        'clave' => $producto->codigoProveedor,
                        'cantidad' => $cantidadDesdeSucursal
                    ];
                }

                $detalleDistribucion[] = [
                    'proveedor_producto_id' => $producto->proveedorProductoId,
                    'clave' => $producto->codigoProveedor,
                    'cantidad_solicitada' => $producto->cantidad,
                    'desde_cedis' => $cantidadDesdeCedis,
                    'desde_sucursal' => $cantidadDesdeSucursal,
                    'origen' => 'Distribuido'
                ];

                $requiereEnviosMultiples = true;
            }
        }

        return [
            'productos_cedis' => $productosCedis,
            'productos_sucursal' => $productosSucursal,
            'distribucion_productos' => $detalleDistribucion,
            'requiere_envios_multiples' => $requiereEnviosMultiples
        ];
    }

    /**
     * Calcular costo total de envío considerando múltiples orígenes
     */
    private function calcularCostoEnvio(array $distribucion, Cliente $cliente): array
    {
        $totales = [
            'subtotal' => 0,
            'iva' => 0,
            'monto_total' => 0
        ];
        $envios = [];

        try {
            // Cotizar envío desde CEDIS
            if (!empty($distribucion['productos_cedis'])) {
                $respuesta = $this->cvaRepository->cotizarPedido([
                    'paqueteria' => $this->PAQUETERIAID,
                    'cp' => $cliente->codigo_postal,
                    'cp_sucursal' => $this->CP_CEDIS_GDL,
                    'productos' => $distribucion['productos_cedis']
                ]);

                if (isset($respuesta['cotizacion'])) {
                    $cot = $respuesta['cotizacion'];

                    $envios['cedis'] = [
                        'origen' => 'CEDIS Guadalajara',
                        'productos' => collect($distribucion['productos_cedis'])->pluck('clave')->toArray(),
                        'cantidad_productos' => collect($distribucion['productos_cedis'])->sum('cantidad'),
                        'subtotal' => $cot['subtotal'] ?? 0,
                        'iva' => $cot['iva'] ?? 0,
                        'monto_total' => $cot['montoTotal'] ?? 0
                    ];

                    $totales['subtotal'] += $cot['subtotal'] ?? 0;
                    $totales['iva'] += $cot['iva'] ?? 0;
                    $totales['monto_total'] += $cot['montoTotal'] ?? 0;
                }
            }

            // Cotizar envío desde Sucursal
            if (!empty($distribucion['productos_sucursal'])) {
                $respuesta = $this->cvaRepository->cotizarPedido([
                    'paqueteria' => $this->PAQUETERIAID,
                    'cp' => $cliente->codigo_postal,
                    'cp_sucursal' => $this->CP_SUCURSAL_GDL,
                    'productos' => $distribucion['productos_sucursal']
                ]);

                if (isset($respuesta['cotizacion'])) {
                    $cot = $respuesta['cotizacion'];

                    $envios['sucursal'] = [
                        'origen' => 'Sucursal Guadalajara',
                        'productos' => collect($distribucion['productos_sucursal'])->pluck('clave')->toArray(),
                        'cantidad_productos' => collect($distribucion['productos_sucursal'])->sum('cantidad'),
                        'subtotal' => $cot['subtotal'] ?? 0,
                        'iva' => $cot['iva'] ?? 0,
                        'monto_total' => $cot['montoTotal'] ?? 0
                    ];

                    $totales['subtotal'] += $cot['subtotal'] ?? 0;
                    $totales['iva'] += $cot['iva'] ?? 0;
                    $totales['monto_total'] += $cot['montoTotal'] ?? 0;
                }
            }

            return [
                'success' => true,
                'data' => [
                    'envios' => $envios,
                    'totales' => [
                        'subtotal' => round($totales['subtotal'], 2),
                        'iva' => round($totales['iva'], 2),
                        'monto_total' => round($totales['monto_total'], 2)
                    ],
                    'requiere_envios_multiples' => $distribucion['requiere_envios_multiples'],
                    'distribucion' => $distribucion['distribucion_productos']
                ]
            ];

        } catch (\Exception $e) {
            \Log::error('Error al calcular costo de envío CVA', [
                'distribucion' => $distribucion,
                'cliente_id' => $cliente->id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Error al calcular costo de envío: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Descontar stock local de forma segura
     * IMPORTANTE: También descuenta disponible_en_promocion si aplica oferta
     */
    private function descontarStockLocal(array $productos, string $campoStock): void
    {
        foreach ($productos as $producto) {
            // 1. Descontar stock físico (stock o stock_cd)
            $actualizado = \DB::table('proveedor_productos')
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

            // 2. Descontar stock de promoción si el producto tiene oferta activa
            $this->descontarStockPromocion($producto['clave'], $producto['cantidad']);
        }

        \Log::info('Stock descontado localmente', [
            'productos' => collect($productos)->pluck('clave')->toArray(),
            'campo_stock' => $campoStock
        ]);
    }

    /**
     * Descontar stock de promoción si aplica
     * 
     * @param string $codigoProveedor
     * @param int $cantidad
     */
    private function descontarStockPromocion(string $codigoProveedor, int $cantidad): void
    {
        try {
            // Buscar si el producto tiene una promoción activa
            $proveedorProducto = ProveedorProducto::where('codigo_proveedor', $codigoProveedor)
                ->first();

            if (!$proveedorProducto) {
                return; // Producto no encontrado, skip
            }

            // Buscar promoción activa más ventajosa (la que se aplicó en enriquecimiento)
            $promocionActiva = $proveedorProducto->promociones()
                ->where('es_oferta', true)
                ->whereNotNull('precio_oferta')
                ->where('precio_oferta', '>', 0)
                ->where('disponible_en_promocion', '>=', $cantidad)
                ->orderBy('precio_oferta', 'asc')
                ->first();

            if (!$promocionActiva) {
                // No hay promoción activa o no hay stock suficiente en promoción
                return;
            }

            // Descontar del stock de promoción
            $actualizado = \DB::table('proveedor_producto_promociones')
                ->where('id', $promocionActiva->id)
                ->where('disponible_en_promocion', '>=', $cantidad)
                ->decrement('disponible_en_promocion', $cantidad);

            if (!$actualizado) {
                \Log::warning('No se pudo descontar stock de promoción', [
                    'codigo_proveedor' => $codigoProveedor,
                    'promocion_id' => $promocionActiva->id,
                    'cantidad_solicitada' => $cantidad,
                    'disponible_en_promocion' => $promocionActiva->disponible_en_promocion
                ]);

                // No lanzamos excepción aquí porque el stock físico ya se descontó
                // Solo logueamos la inconsistencia para revisión manual
            } else {
                \Log::info('Stock de promoción descontado', [
                    'codigo_proveedor' => $codigoProveedor,
                    'promocion_id' => $promocionActiva->id,
                    'cantidad' => $cantidad,
                    'clave_promocion' => $promocionActiva->clave_promocion
                ]);
            }

        } catch (\Exception $e) {
            // Loguear el error pero no detener el proceso
            // El stock físico ya fue descontado correctamente
            \Log::error('Error al descontar stock de promoción', [
                'codigo_proveedor' => $codigoProveedor,
                'cantidad' => $cantidad,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Validar si el cliente está en el alcance de envío
     * 
     * @throws ShippingOutOfRangeException
     */
    private function validarAlcanceDeEnvio(Cliente $cliente): bool
    {
        try {
            $estado = ProveedorEstado::where('descripcion', strtoupper(trim($cliente->estado)))->first();
            
            if (!$estado) {
                throw new ShippingOutOfRangeException(
                    $cliente->estado,
                    $cliente->ciudad,
                    'CVA'
                );
            }

            $ciudad = strtoupper(trim($cliente->ciudad));
            $ciudadExiste = $estado->ciudades()->where('descripcion', $ciudad)->exists();
            
            if (!$ciudadExiste) {
                throw new ShippingOutOfRangeException(
                    $cliente->estado,
                    $cliente->ciudad,
                    'CVA'
                );
            }

            return true;

        } catch (ShippingOutOfRangeException $e) {
            throw $e;
        } catch (\Exception $e) {
            \Log::error('Error al validar alcance de envío', [
                'cliente_id' => $cliente->id,
                'error' => $e->getMessage()
            ]);
            
            throw new ShippingException(
                'Error al validar alcance de envío',
                [
                    'cliente_id' => $cliente->id,
                    'error_original' => $e->getMessage()
                ]
            );
        }
    }

    /**
     * Formatear datos de envío para CVA
     */
    private function formatearDatosEnvio(Cliente $cliente, ?array $datosEnvio): array
    {
        $estado = ProveedorEstado::where('descripcion', strtoupper(trim($cliente->estado)))->first();
        $ciudad = $estado->ciudades()->where('descripcion', strtoupper(trim($cliente->ciudad)))->first();

        $flete = [
            'calle' => $cliente->calle ?? '',
            'numero' => $cliente->numero_exterior ?? '',
            'numero_interior' => $cliente->numero_interior ?? '',
            'cp' => $cliente->codigo_postal ?? '',
            'estado' => (int)$estado->clave,
            'ciudad' => (int)$ciudad->clave,
            'paqueteria' => $this->PAQUETERIAID,
            'atencion' => $cliente->nombre ?? '',
            'colonia' => $cliente->colonia ?? '' 
        ];

        if ($datosEnvio) {
            $flete = array_merge($flete, $datosEnvio);
        }

        return $flete;
    }

    public function soporta(int $proveedorId): bool
    {
        return Proveedor::where('codigo_proveedor', 'cva')
            ->where('id', $proveedorId)
            ->exists();
    }

    public function obtenerEstatus(string $folioPedido): string
    {
        // TODO: Implementar consulta a API de CVA
        return 'pendiente';
    }

    public function cancelarPedido(string $folioPedido): bool
    {
        try {
            \Log::info('Solicitando cancelación de pedido CVA', [
                'folio' => $folioPedido
            ]);
            
            // TODO: Implementar llamada a API de CVA para cancelar
            return true;

        } catch (\Exception $e) {
            \Log::error('Error al cancelar pedido CVA', [
                'folio' => $folioPedido,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    public function obtenerNombre():string{
        return 'cva';
    }
}