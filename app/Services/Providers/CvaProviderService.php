<?php

namespace App\Services\Providers;

use App\Contratos\ProveedorServiceInterface;
use App\Data\Pedidos\PedidoProveedorRequestData;
use App\Data\Response\ApiResponseData;
use App\Exceptions\Cva\CvaStockException;
use App\Exceptions\Orders\ShippingException;
use App\Exceptions\Orders\ShippingOutOfRangeException;
use App\Exceptions\Orders\ShippingQuoteException;
use App\Models\Cliente;
use App\Models\Proveedor;
use App\Models\ProveedorEstado;
use App\Models\ProveedorProducto;
use App\Repository\CvaRepository;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;

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

    public function crearPedido(PedidoProveedorRequestData $request, Cliente $cliente): array
    {
        try {
            // Validaciones previas (fuera de transacción)
            if (!$this->validarAlcanceDeEnvio($cliente)) {
                return [
                    'success' => false,
                    'error' => 'El cliente no se encuentra en el alcance de envío'
                ];
            }

            $this->validarDisponibilidad($request->productos);
            $distribucion = $this->distribucionStock($request->productos);
            $costoEnvioData = $this->calcularCostoEnvio($distribucion, $cliente);

            if (!$costoEnvioData->success) {
                return [
                    'success' => false,
                    'error' => 'Error al calcular costo de envío'
                ];
            }

            //TRANSACCIÓN: Descontar primero, crear después
            return \DB::transaction(function () use ($request, $cliente, $distribucion, $costoEnvioData) {
                $envios = $costoEnvioData->data['envios'];
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
                    // 1️⃣ Descontar stock_cd primero
                    $this->descontarStockLocal($distribucion['productos_cedis'], 'stock_cd');

                    // 2️⃣ Crear pedido en CVA
                    $payload = array_merge($payloadBase, [
                        'codigo_sucursal' => $this->CLAVE_CEDIS_GDL,
                        'productos' => array_values($distribucion['productos_cedis'])
                    ]);

                    $response = $this->cvaRepository->crearOrden($payload);
                    // Si falla → ROLLBACK automático ✅

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
                    // 1️⃣ Descontar stock primero
                    $this->descontarStockLocal($distribucion['productos_sucursal'], 'stock');

                    // 2️⃣ Crear pedido en CVA
                    $payload = array_merge($payloadBase, [
                        'codigo_sucursal' => $this->CLAVE_SUCURSAL_GDL,
                        'productos' => array_values($distribucion['productos_sucursal'])
                    ]);

                    $response = $this->cvaRepository->crearOrden($payload);
                    // Si falla → ROLLBACK de TODO ✅

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

                // 3️⃣ Solo llega aquí si TODO fue exitoso
                return [
                    'success' => true,
                    'data' => $respuestas,
                    'metadata' => [
                        'requiere_envios_multiples' => $distribucion['requiere_envios_multiples'],
                        'total_envios' => $costoEnvioData->data['totales'],
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

        } catch (\Exception $e) {
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
     * Descontar stock local de forma segura
     */
    private function descontarStockLocal(array $productos, string $campoStock): void
    {
        foreach ($productos as $producto) {
            $actualizado = \DB::table('proveedor_productos')
                ->where('codigo_proveedor', $producto['clave'])
                ->where($campoStock, '>=', $producto['cantidad']) // Validación extra
                ->decrement($campoStock, $producto['cantidad']);

            if (!$actualizado) {
                throw new CvaStockException(
                    "No se pudo descontar stock de {$producto['clave']}. " .
                    "Posible inconsistencia en stock disponible.",
                    500
                );
            }
        }

        \Log::info('Stock descontado localmente', [
            'productos' => collect($productos)->pluck('clave')->toArray(),
            'campo' => $campoStock
        ]);
    }

    /**
     * Cotizar envío sin crear pedido
     */
    /**
     * Cotizar envío sin crear pedido
     * 
     * @throws ShippingOutOfRangeException
     * @throws ShippingQuoteException
     */
    public function cotizarEnvio(array $productos, Cliente $cliente): array
    {
        // Validar alcance primero - LANZA EXCEPCIÓN si falla
        $this->validarAlcanceDeEnvio($cliente);

        try {
            // Obtener distribución
            $distribucion = $this->distribucionStock($productos);
            
            // Calcular costos
            $resultado = $this->calcularCostoEnvio($distribucion, $cliente);
            
            if (!$resultado->success) {
                throw new ShippingQuoteException(
                    'CVA',
                    $resultado->message ?? 'Error desconocido al cotizar',
                    $productos
                );
            }

            return $resultado->data['totales'];

        } catch (ShippingOutOfRangeException | ShippingQuoteException $e) {
            throw $e; // Re-lanzar excepciones de negocio
        } catch (\Exception $e) {
            \Log::error('Error al cotizar envío CVA', [
                'productos' => $productos,
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
     * Validar disponibilidad de stock para todos los productos
     */
    public function validarDisponibilidad(array $productos): bool
    {
        // Obtener códigos de proveedor
        $codigosProveedor = collect($productos)->pluck('codigo_proveedor')->toArray();

        // Cargar todos los productos de una vez
        $productosProveedor = ProveedorProducto::whereIn('codigo_proveedor', $codigosProveedor)
            ->get()
            ->keyBy('codigo_proveedor');

        foreach ($productos as $producto) {
            $codigoProveedor = $producto['codigo_proveedor'];
            $proveedorProducto = $productosProveedor->get($codigoProveedor);
            
            if (!$proveedorProducto) {
                throw new CvaStockException(
                    "Producto {$codigoProveedor} no encontrado",
                    404
                );
            }

            $cantidadSolicitada = $producto['cantidad'];
            $stockCedis = $proveedorProducto->stock_cd ?? 0;
            $stockSucursal = $proveedorProducto->stock ?? 0;
            $stockTotal = $stockCedis + $stockSucursal;

            // Verificar stock total
            if ($stockTotal < $cantidadSolicitada) {
                throw new CvaStockException(
                    "Stock insuficiente para {$proveedorProducto->codigo_proveedor}. " .
                    "Solicitado: {$cantidadSolicitada}, " .
                    "Disponible: {$stockTotal} (CEDIS: {$stockCedis}, Sucursal: {$stockSucursal})",
                    400
                );
            }
        }

        return true;
    }

    /**
     * Distribuir productos entre CEDIS y Sucursal según stock disponible
     */
    public function distribucionStock(array $productos): array
    {
        $productosCedis = [];
        $productosSucursal = [];
        $detalleDistribucion = [];
        $requiereEnviosMultiples = false;

        foreach ($productos as $producto) {

            $cantidadSolicitada = $producto['cantidad_a_cotizar'] ?? $producto['cantidad'];
            $stockCedis = $producto['stock_cd'] ?? 0;
            $stockSucursal = $producto['stock'] ?? 0;

            // Caso 1: Todo el stock está en CEDIS
            if ($stockCedis >= $cantidadSolicitada) {
                $productosCedis[] = [
                    'clave' => $producto['codigo_proveedor'],
                    'cantidad' => $cantidadSolicitada
                ];

                $detalleDistribucion[] = [
                    'proveedor_producto_id' => $producto['id'] ?? $producto['proveedor_producto_id'],
                    'clave' => $producto['codigo_proveedor'],
                    'cantidad_solicitada' => $cantidadSolicitada,
                    'desde_cedis' => $cantidadSolicitada,
                    'desde_sucursal' => 0,
                    'origen' => 'CEDIS'
                ];
            }
            // Caso 2: Todo el stock está en Sucursal
            elseif ($stockSucursal >= $cantidadSolicitada) {
                $productosSucursal[] = [
                    'clave' => $producto['codigo_proveedor'],
                    'cantidad' => $cantidadSolicitada
                ];

                $detalleDistribucion[] = [
                    'proveedor_producto_id' => $producto['id'] ?? $producto['proveedor_producto_id'],
                    'clave' => $producto['codigo_proveedor'],
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
                        'clave' => $producto['codigo_proveedor'],
                        'cantidad' => $cantidadDesdeCedis
                    ];
                }

                if ($cantidadDesdeSucursal > 0) {
                    $productosSucursal[] = [
                        'clave' => $producto['codigo_proveedor'],
                        'cantidad' => $cantidadDesdeSucursal
                    ];
                }

                $detalleDistribucion[] = [
                    'proveedor_producto_id' => $producto['id'] ?? $producto['proveedor_producto_id'],
                    'clave' => $producto['codigo_proveedor'],
                    'cantidad_solicitada' => $cantidadSolicitada,
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
    public function calcularCostoEnvio(array $distribucion, Cliente $cliente): ApiResponseData
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

            return new ApiResponseData(
                success: true,
                data: [
                    'envios' => $envios,
                    'totales' => [
                        'subtotal' => round($totales['subtotal'], 2),
                        'iva' => round($totales['iva'], 2),
                        'monto_total' => round($totales['monto_total'], 2)
                    ],
                    'requiere_envios_multiples' => $distribucion['requiere_envios_multiples'],
                    'distribucion' => $distribucion['distribucion_productos']
                ]
            );

        } catch (\Exception $e) {
            \Log::error('Error al calcular costo de envío CVA', [
                'distribucion' => $distribucion,
                'cliente_id' => $cliente->id,
                'error' => $e->getMessage()
            ]);

            return new ApiResponseData(
                success: false,
                message: 'Error al calcular costo de envío: ' . $e->getMessage()
            );
        }
    }

    /**
     * Validar si el cliente está en el alcance de envío
     * 
     * @throws ShippingOutOfRangeException
     */
    public function validarAlcanceDeEnvio(Cliente $cliente): bool
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
            throw $e; // Re-lanzar excepciones de negocio
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
     * Obtener estatus de un pedido
     */
    public function obtenerEstatus(string $folioPedido): string
    {
        // TODO: Implementar consulta a API de CVA
        return 'pendiente';
    }

    /**
     * Cancelar un pedido (para rollback)
     */
    public function cancelarPedido(string $folioPedido): bool
    {
        try {
            // TODO: Implementar llamada a API de CVA para cancelar
            \Log::info('Solicitando cancelación de pedido CVA', [
                'folio' => $folioPedido
            ]);

            // Por ahora retornar true (implementar cuando CVA tenga endpoint)
            return true;

        } catch (\Exception $e) {
            \Log::error('Error al cancelar pedido CVA', [
                'folio' => $folioPedido,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Verificar si este servicio soporta un proveedor específico
     */
    public function soporta(int $proveedorId): bool
    {
        return Proveedor::where('codigo_proveedor', 'cva')
            ->where('id', $proveedorId)
            ->exists();
    }

    /**
     * Formatear datos de envío
     */
    private function formatearDatosEnvio(Cliente $cliente, ?array $datosEnvio): array
    {
        $estado = ProveedorEstado::where('descripcion', strtoupper(trim($cliente->estado)))->first();
        
        if (!$estado) {
            throw new BadRequestException('El estado del cliente no se encuentra en el alcance de envío de CVA');
        }

        $ciudad = $estado->ciudades()->where('descripcion', strtoupper(trim($cliente->ciudad)))->first();
        
        if (!$ciudad) {
            throw new BadRequestException('La ciudad del cliente no se encuentra en el alcance de envío de CVA');
        }

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

        // Sobrescribir con datos de envío específicos si se proporcionan
        if ($datosEnvio) {
            $flete = array_merge($flete, $datosEnvio);
        }

        return $flete;
    }
}