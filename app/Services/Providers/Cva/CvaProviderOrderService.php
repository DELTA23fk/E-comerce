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
use App\Models\TipoCambioMoneda;
use App\Repository\CvaRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Servicio de pedidos CVA.
 *
 * RESPONSABILIDADES (solo estas):
 *  - Enriquecer productos con precios y promociones CVA.
 *  - Validar stock contra almacenes CVA.
 *  - Distribuir productos por almacén y calcular costo de envío.
 *  - Crear órdenes en la API de CVA.
 *  - Descontar stock localmente tras confirmar con CVA.
 *
 * NO HACE:
 *  - Crear Pedido ni PedidoProveedor en base de datos (responsabilidad del orquestador).
 *  - Conocer el estado del pedido maestro.
 */
class CvaProviderOrderService implements ProveedorServiceInterface
{
    // Claves oficiales CVA — coinciden con proveedor_almacenes.almacen_id_externo
    private const CLAVE_CEDIS_GDL    = '46';
    private const CLAVE_SUCURSAL_GDL = '1';
    private const PAQUETERIAID       = 4;
    private const CP_CEDIS_GDL       = 45640;
    private const CP_SUCURSAL_GDL    = 44900;
    private const CLAVES_CEDIS       = ['46', '51', '54'];

    public function __construct(
        private CvaRepository $cvaRepository,
    ) {}

    // =========================================================================
    // ENRIQUECIMIENTO
    // =========================================================================

    /**
     * {@inheritdoc}
     *
     * Aplica precios de lista y promociones CVA activas.
     * Si la promoción tiene stock insuficiente para la cantidad solicitada,
     * se descarta y se usa el precio regular (se registra en log).
     */
    public function enriquecerProductos(array $productosBasicos): array
    {
        $claves = collect($productosBasicos)->pluck('codigo_proveedor')->unique()->toArray();

        $productosDb = ProveedorProducto::whereIn('codigo_proveedor', $claves)
            ->with(['precio', 'promociones' => function ($query) {
                $query->where('es_oferta', true)
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

            // Log si hay promociones que no alcanzan el stock mínimo
            $promocionesInsuficientes = $proveedorProducto->promociones
                ->filter(fn($p) => $p->disponible_en_promocion > 0
                                && $p->disponible_en_promocion < $cantidadSolicitada);

            if ($promocionesInsuficientes->isNotEmpty()) {
                Log::info('Promoción descartada por stock insuficiente', [
                    'codigo_proveedor'    => $productoBasico['codigo_proveedor'],
                    'cantidad_solicitada' => $cantidadSolicitada,
                    'promociones'         => $promocionesInsuficientes->map(fn($p) => [
                        'clave'                    => $p->clave_promocion,
                        'stock_promo'              => $p->disponible_en_promocion,
                        'precio_con_descuento_mxn' => $p->precio_con_descuento_mxn,
                    ])->toArray(),
                ]);
            }

            return ProductoEnriquecidoData::fromProveedorProducto($proveedorProducto, $cantidadSolicitada);
        })->toArray();
    }

    /**
     * Construye ProductoEnriquecidoData básicos para cotización de envío rápida.
     *
     * NO aplica precios ni promociones. Solo los campos que CVA necesita para
     * calcular peso/volumen/flete. El orquestador llama este método en lugar
     * de enriquecerProductos() cuando el único objetivo es cotizar envío.
     *
     * @param  array $productosBasicos  [['codigo_proveedor' => 'XX', 'cantidad' => 2], ...]
     * @return ProductoEnriquecidoData[]
     */
    public function prepararParaCotizacion(array $productosBasicos): array
    {
        $claves = collect($productosBasicos)->pluck('codigo_proveedor')->unique()->toArray();

        $productosDb = DB::table('proveedor_productos as pp')
            ->join('proveedores as prov', 'pp.proveedor_id', '=', 'prov.id')
            ->where('prov.codigo_proveedor', 'cva')
            ->whereIn('pp.codigo_proveedor', $claves)
            ->whereNull('pp.deleted_at')
            ->select('pp.id', 'pp.codigo_proveedor', 'pp.proveedor_id', 'pp.producto_id', 'pp.stock_total')
            ->get()
            ->keyBy('codigo_proveedor');

        return collect($productosBasicos)->map(function ($productoBasico) use ($productosDb) {
            $row = $productosDb->get($productoBasico['codigo_proveedor']);

            if (!$row) {
                throw new \App\Exceptions\Orders\ProductNotFoundException($productoBasico['codigo_proveedor']);
            }

            return new ProductoEnriquecidoData(
                proveedorProductoId: $row->id,
                codigoProveedor:     $row->codigo_proveedor,
                cantidad:            $productoBasico['cantidad'],
                precioUnitario:      0,
                precioOriginal:      0,
                proveedorId:         $row->proveedor_id,
                productoId:          $row->producto_id,
                enOferta:            false,
                descuentoPorcentaje: null,
                clavePromocion:      null,
                metadataProveedor:   ['stock_total' => $row->stock_total],
            );
        })->toArray();
    }

    // =========================================================================
    // VALIDACIÓN DE DISPONIBILIDAD
    // =========================================================================

    /**
     * {@inheritdoc}
     *
     * Lanza CvaStockException si el stock TOTAL (todos los almacenes) es insuficiente.
     * Si $almacenPreferido no cubre por sí solo, se registra en log pero no es error fatal.
     */
    public function validarDisponibilidad(array $productos, string|int|null $almacenPreferido = null): bool
    {
        foreach ($productos as $producto) {
            $stockPorAlmacen = $this->obtenerStockPorAlmacen($producto->codigoProveedor);
            $stockTotal      = $stockPorAlmacen->sum('cantidad');

            if ($stockTotal < $producto->cantidad) {
                throw new CvaStockException(
                    "Stock insuficiente para {$producto->codigoProveedor}. " .
                    "Solicitado: {$producto->cantidad}, disponible: {$stockTotal}.",
                    400,
                );
            }

            if ($almacenPreferido !== null) {
                $almacen        = $this->resolverAlmacenPreferido($stockPorAlmacen, $almacenPreferido);
                $stockEnAlmacen = $almacen?->cantidad ?? 0;

                if ($stockEnAlmacen < $producto->cantidad) {
                    Log::info('Almacén preferido sin stock suficiente — se distribuirá', [
                        'codigo_proveedor'  => $producto->codigoProveedor,
                        'almacen_preferido' => $almacenPreferido,
                        'stock_en_almacen'  => $stockEnAlmacen,
                        'solicitado'        => $producto->cantidad,
                        'stock_total'       => $stockTotal,
                    ]);
                }
            }

            if ($producto->enOferta && $producto->clavePromocion) {
                $stockPromocion = $this->obtenerStockPromocion($producto->codigoProveedor, $producto->clavePromocion);

                if ($stockPromocion !== null && $stockPromocion < $producto->cantidad) {
                    throw new CvaStockException(
                        "Stock insuficiente en promoción para {$producto->codigoProveedor}. " .
                        "Solicitado: {$producto->cantidad}, disponible en promo: {$stockPromocion}.",
                        400,
                    );
                }
            }
        }

        return true;
    }

    // =========================================================================
    // COTIZACIÓN DE ENVÍO
    // =========================================================================

    /**
     * {@inheritdoc}
     *
     * Distribuye productos por almacén CVA y cotiza cada envío parcial.
     */
    public function cotizarEnvio(
        array           $productos,
        Cliente         $cliente,
        string|int|null $almacenPreferido = null,
    ): CotizacionEnvioData {
        $this->validarAlcanceDeEnvio($cliente);

        try {
            $distribucion = $this->distribucionStock($productos, $almacenPreferido);
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
                    'almacen_preferido'         => $distribucion['almacen_preferido'],
                    'envios'                    => $resultado['data']['envios'],
                    'requiere_envios_multiples' => $resultado['data']['requiere_envios_multiples'],
                    'distribucion'              => $resultado['data']['distribucion'],
                ],
            );

        } catch (ShippingOutOfRangeException | ShippingQuoteException $e) {
            throw $e;

        } catch (\Exception $e) {
            Log::error('Error al cotizar envío CVA', [
                'productos'        => collect($productos)->map->codigoProveedor->toArray(),
                'cliente_id'       => $cliente->id,
                'almacen_preferido' => $almacenPreferido,
                'error'            => $e->getMessage(),
            ]);
            throw new ShippingQuoteException('CVA', 'Error inesperado: ' . $e->getMessage(), $productos);
        }
    }

    // =========================================================================
    // CREACIÓN DE PEDIDO
    // =========================================================================

    /**
     * {@inheritdoc}
     *
     * almacenPreferido viaja dentro de $request->almacenPreferido.
     *
     * Devuelve array estandarizado (ver interface para estructura completa).
     */
    public function crearPedido(
        PedidoProveedorRequestData $request,
        Cliente                    $cliente,
    ): array {
        try {
            $this->validarAlcanceDeEnvio($cliente);

            $productosEnriquecidos = $this->enriquecerProductos($request->productos);
            $this->validarDisponibilidad($productosEnriquecidos, $request->almacenPreferido);

            $distribucion   = $this->distribucionStock($productosEnriquecidos, $request->almacenPreferido);
            $costoEnvioData = $this->calcularCostoEnvio($distribucion, $cliente);

            if (!$costoEnvioData['success']) {
                return ['success' => false, 'error' => 'Error al calcular costo de envío'];
            }

            return DB::transaction(function () use ($request, $cliente, $distribucion, $costoEnvioData) {
                $envios      = $costoEnvioData['data']['envios'];
                $respuestas  = [];
                $payloadBase = [
                    'test'          => config('app.env') !== 'production' ? 1 : 0,
                    'num_oc'        => $request->numeroOrden,
                    'observaciones' => $request->observaciones ?? '',
                    'tipo_flete'    => 'FF',
                    'cotiza_flete'  => 1, 
                    'flete'         => $this->formatearDatosEnvio($cliente, $request->datosEnvio),
                ];

                foreach ($distribucion['por_almacen'] as $clave => $grupo) {
                    if (empty($grupo['productos'])) continue;

                    $this->descontarStockAlmacen($grupo['productos'], $clave);
                    $this->descontarStockTotal($grupo['productos']);

                    $response     = $this->cvaRepository->crearOrden(array_merge($payloadBase, [
                        'codigo_sucursal' => (int) $clave,
                        'productos'       => $grupo['productos'],
                    ]));
                    // Validar que la respuesta sea válida y contenga los datos esperados
                    if (!is_array($response) || empty($response)) {
                        throw new CvaApiException(
                            "Respuesta inválida de CVA al crear orden",
                            400,
                            ['response' => $response, 'almacen' => $clave]
                        );
                    }

                    if (isset($response['error']) || isset($response['result']) && $response['result'] === 0) {
                        throw new CvaApiException(
                            "Error en respuesta de CVA: " . ($response['error'] ?? $response['message'] ?? 'Error desconocido'),
                            400,
                            $response
                        );
                    }

                    if (!isset($response['pedido'])) {
                        throw new CvaApiException(
                            "CVA no retornó folio de pedido",
                            400,
                            $response
                        );
                    }
                    $respuestas[] = $this->mapearRespuestaOrden($response, $response['flete'] ?? [], $grupo['nombre']);
                }

                return [
                    'success'  => true,
                    'data'     => $respuestas,
                    'metadata' => [
                        'almacen_preferido'         => $distribucion['almacen_preferido'],
                        'requiere_envios_multiples' => $distribucion['requiere_envios_multiples'],
                        'total_envios'              => $costoEnvioData['data']['totales'],
                        'distribucion'              => $distribucion['distribucion_productos'],
                    ],
                ];
            });

        } catch (CvaStockException $e) {
            Log::warning('Stock insuficiente en CVA', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];

        } catch (CvaApiException | \Exception $e) {
            Log::error('Error al crear pedido CVA', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return ['success' => false, 'error' => 'Error al procesar pedido con CVA: ' . $e->getMessage()];
        }
    }

    // =========================================================================
    // DISTRIBUCIÓN DE STOCK
    // =========================================================================

    /**
     * Determina desde qué almacén(es) se despacha cada producto.
     *
     * Prioridad por producto:
     *   0. Almacén preferido cubre toda la demanda → solo él
     *   1. CEDIS GDL principal (clave 46) cubre toda la demanda → solo él
     *   2. Otro CEDIS (51, 54) cubre toda la demanda → ese CEDIS
     *   3. Distribución general: agotar CEDIS primero, luego sucursales
     */
    private function distribucionStock(
        array           $productos,
        string|int|null $almacenPreferido = null,
    ): array {
        $porAlmacen           = [];
        $detalleDistribucion  = [];
        $requiereMultiples    = false;
        $almacenPreferidoInfo = null;

        foreach ($productos as $producto) {
            $stockPorAlmacen = $this->obtenerStockPorAlmacen($producto->codigoProveedor);
            $demanda         = $producto->cantidad;

            // ── 0. Almacén preferido cubre todo ──────────────────────────────
            if ($almacenPreferido !== null) {
                $almacenRow = $this->resolverAlmacenPreferido($stockPorAlmacen, $almacenPreferido);

                if ($almacenRow && $almacenRow->cantidad >= $demanda) {
                    $clave                = $almacenRow->almacen_id_externo;
                    $almacenPreferidoInfo ??= ['clave' => $clave, 'nombre' => $almacenRow->nombre];

                    $porAlmacen = $this->agregarAGrupo(
                        $porAlmacen, $clave, $almacenRow->nombre,
                        (bool) $almacenRow->es_cd, (int) ($almacenRow->codigo_postal ?? 0),
                        $producto->codigoProveedor, $demanda,
                    );
                    $detalleDistribucion[] = $this->detalleDistribucion($producto, [$clave => $demanda], 'preferido');
                    continue;
                }

                // Stock parcial en preferido → úsalo primero y complementa
                if ($almacenRow && $almacenRow->cantidad > 0) {
                    $almacenPreferidoInfo ??= [
                        'clave'  => $almacenRow->almacen_id_externo,
                        'nombre' => $almacenRow->nombre,
                    ];

                    $distribuido = $this->distribuirConPreferido(
                        $stockPorAlmacen, $almacenRow, $demanda, $producto->codigoProveedor, $porAlmacen,
                    );

                    $porAlmacen            = $distribuido['por_almacen'];
                    $detalleDistribucion[] = $this->detalleDistribucion($producto, $distribuido['asignado'], 'preferido-parcial');

                    if (count($distribuido['asignado']) > 1) $requiereMultiples = true;
                    continue;
                }

                Log::info('Almacén preferido sin stock, usando flujo normal', [
                    'codigo_proveedor'  => $producto->codigoProveedor,
                    'almacen_preferido' => $almacenPreferido,
                ]);
            }

            // ── 1. CEDIS GDL principal cubre todo ────────────────────────────
            $cedisPrincipal = $stockPorAlmacen->firstWhere('almacen_id_externo', self::CLAVE_CEDIS_GDL);

            if ($cedisPrincipal && $cedisPrincipal->cantidad >= $demanda) {
                $porAlmacen = $this->agregarAGrupo(
                    $porAlmacen, self::CLAVE_CEDIS_GDL, $cedisPrincipal->nombre,
                    true, self::CP_CEDIS_GDL, $producto->codigoProveedor, $demanda,
                );
                $detalleDistribucion[] = $this->detalleDistribucion($producto, [self::CLAVE_CEDIS_GDL => $demanda], 'cedis-principal');
                continue;
            }

            // ── 2. Otro CEDIS individual cubre todo ───────────────────────────
            $otroCedis = $stockPorAlmacen
                ->whereIn('almacen_id_externo', self::CLAVES_CEDIS)
                ->where('almacen_id_externo', '!=', self::CLAVE_CEDIS_GDL)
                ->where('cantidad', '>=', $demanda)
                ->sortByDesc('cantidad')
                ->first();

            if ($otroCedis) {
                $porAlmacen = $this->agregarAGrupo(
                    $porAlmacen, $otroCedis->almacen_id_externo, $otroCedis->nombre,
                    true, (int) ($otroCedis->codigo_postal ?? 0), $producto->codigoProveedor, $demanda,
                );
                $detalleDistribucion[] = $this->detalleDistribucion($producto, [$otroCedis->almacen_id_externo => $demanda], 'otro-cedis');
                continue;
            }

            // ── 3. Distribución general: CEDIS primero, luego sucursales ──────
            [$porAlmacen, $distribuido, $restante] = $this->distribuirGeneral(
                $stockPorAlmacen, $demanda, $producto->codigoProveedor, $porAlmacen,
            );

            if ($restante > 0) {
                throw new CvaStockException(
                    "No hay stock suficiente en ningún almacén para {$producto->codigoProveedor}. " .
                    "Faltaron: {$restante} unidades.",
                    400,
                );
            }

            if (count($distribuido) > 1) $requiereMultiples = true;

            $detalleDistribucion[] = $this->detalleDistribucion($producto, $distribuido, 'distribuido');
        }

        return [
            'por_almacen'               => $porAlmacen,
            'distribucion_productos'    => $detalleDistribucion,
            'requiere_envios_multiples' => $requiereMultiples,
            'almacen_preferido'         => $almacenPreferidoInfo,
        ];
    }

    private function distribuirConPreferido(
        Collection $stockPorAlmacen,
        object     $almacenRow,
        int        $demanda,
        string     $codigoProveedor,
        array      $porAlmacen,
    ): array {
        $asignado = [];
        $restante = $demanda;

        $tomarPreferido                            = min($almacenRow->cantidad, $restante);
        $asignado[$almacenRow->almacen_id_externo] = $tomarPreferido;
        $restante                                 -= $tomarPreferido;

        $porAlmacen = $this->agregarAGrupo(
            $porAlmacen, $almacenRow->almacen_id_externo, $almacenRow->nombre,
            (bool) $almacenRow->es_cd, (int) ($almacenRow->codigo_postal ?? 0),
            $codigoProveedor, $tomarPreferido,
        );

        if ($restante > 0) {
            $restanteStock = $stockPorAlmacen->where('almacen_id_externo', '!=', $almacenRow->almacen_id_externo);

            [$porAlmacen, $distribuido, $restante] = $this->distribuirGeneral(
                $restanteStock, $restante, $codigoProveedor, $porAlmacen,
            );

            $asignado = array_merge($asignado, $distribuido);

            if ($restante > 0) {
                throw new CvaStockException(
                    "Stock insuficiente para {$codigoProveedor} incluso con almacén preferido. Faltaron: {$restante}.",
                    400,
                );
            }
        }

        return ['por_almacen' => $porAlmacen, 'asignado' => $asignado];
    }

    private function distribuirGeneral(
        Collection $stockPorAlmacen,
        int        $demanda,
        string     $codigoProveedor,
        array      $porAlmacen,
    ): array {
        $distribuido = [];
        $restante    = $demanda;

        foreach ($stockPorAlmacen->whereIn('almacen_id_externo', self::CLAVES_CEDIS)->sortByDesc('cantidad') as $a) {
            if ($restante <= 0 || $a->cantidad <= 0) continue;

            $tomar                               = min($a->cantidad, $restante);
            $distribuido[$a->almacen_id_externo] = ($distribuido[$a->almacen_id_externo] ?? 0) + $tomar;
            $restante                           -= $tomar;
            $porAlmacen = $this->agregarAGrupo($porAlmacen, $a->almacen_id_externo, $a->nombre, true, (int) ($a->codigo_postal ?? 0), $codigoProveedor, $tomar);
        }

        if ($restante > 0) {
            foreach ($stockPorAlmacen->whereNotIn('almacen_id_externo', self::CLAVES_CEDIS)->sortByDesc('cantidad') as $a) {
                if ($restante <= 0 || $a->cantidad <= 0) continue;

                $tomar                               = min($a->cantidad, $restante);
                $distribuido[$a->almacen_id_externo] = ($distribuido[$a->almacen_id_externo] ?? 0) + $tomar;
                $restante                           -= $tomar;
                $porAlmacen = $this->agregarAGrupo($porAlmacen, $a->almacen_id_externo, $a->nombre, false, (int) ($a->codigo_postal ?? 0), $codigoProveedor, $tomar);
            }
        }

        return [$porAlmacen, $distribuido, $restante];
    }

    private function resolverAlmacenPreferido(Collection $stockPorAlmacen, string|int $almacenPreferido): ?object
    {
        $porClave = $stockPorAlmacen->firstWhere('almacen_id_externo', (string) $almacenPreferido);
        if ($porClave) return $porClave;

        return $stockPorAlmacen->firstWhere('almacen_id', (int) $almacenPreferido) ?? null;
    }

    // =========================================================================
    // HELPERS — AGRUPACIÓN Y DETALLE
    // =========================================================================

    private function agregarAGrupo(array $porAlmacen, string $clave, string $nombre, bool $esCd, int $cp, string $codigoProveedor, int $cantidad): array
    {
        if (!isset($porAlmacen[$clave])) {
            $porAlmacen[$clave] = ['nombre' => $nombre, 'es_cd' => $esCd, 'cp' => $cp, 'productos' => []];
        }

        $encontrado = false;
        foreach ($porAlmacen[$clave]['productos'] as &$prod) {
            if ($prod['clave'] === $codigoProveedor) {
                $prod['cantidad'] += $cantidad;
                $encontrado        = true;
                break;
            }
        }
        unset($prod);

        if (!$encontrado) {
            $porAlmacen[$clave]['productos'][] = ['clave' => $codigoProveedor, 'cantidad' => $cantidad];
        }

        return $porAlmacen;
    }

    private function detalleDistribucion($producto, array $distribuido, string $origen): array
    {
        return [
            'proveedor_producto_id' => $producto->proveedorProductoId,
            'clave'                 => $producto->codigoProveedor,
            'cantidad_solicitada'   => $producto->cantidad,
            'distribucion'          => $distribuido,
            'almacenes_usados'      => count($distribuido),
            'origen_estrategia'     => $origen,
        ];
    }

    // =========================================================================
    // COSTO DE ENVÍO
    // =========================================================================

    private function calcularCostoEnvio(array $distribucion, Cliente $cliente): array
    {
        $totales = ['subtotal' => 0, 'iva' => 0, 'monto_total' => 0];
        $envios  = [];

        try {
            foreach ($distribucion['por_almacen'] as $clave => $grupo) {
                if (empty($grupo['productos'])) continue;

                $respuesta = $this->cvaRepository->cotizarPedido([
                    'paqueteria'  => self::PAQUETERIAID,
                    'cp'          => $cliente->codigo_postal,
                    'cp_sucursal' => $grupo['cp'],
                    'productos'   => $grupo['productos'],
                ]);

                if (isset($respuesta['cotizacion'])) {
                    $cot            = $respuesta['cotizacion'];
                    $envios[$clave] = [
                        'origen'             => $grupo['nombre'],
                        'es_cd'              => $grupo['es_cd'],
                        'productos'          => collect($grupo['productos'])->pluck('clave')->toArray(),
                        'cantidad_productos' => collect($grupo['productos'])->sum('cantidad'),
                        'subtotal'           => $cot['subtotal']   ?? 0,
                        'iva'                => $cot['iva']        ?? 0,
                        'monto_total'        => $cot['montoTotal'] ?? 0,
                        'moneda'             => 'MXN',
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
            Log::error('Error al calcular costo de envío CVA', ['cliente_id' => $cliente->id, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Error al calcular costo de envío: ' . $e->getMessage()];
        }
    }

    // =========================================================================
    // HELPERS — STOCK
    // =========================================================================

    private function obtenerStockPorAlmacen(string $codigoProveedor): Collection
    {
        return DB::table('almacen_producto_stock as aps')
            ->join('proveedor_almacenes as a', 'aps.proveedor_almacen_id', '=', 'a.id')
            ->join('proveedor_productos as pp', 'aps.proveedor_producto_id', '=', 'pp.id')
            ->join('proveedores as prov', 'pp.proveedor_id', '=', 'prov.id')
            ->where('prov.codigo_proveedor', 'cva')
            ->where('pp.codigo_proveedor', $codigoProveedor)
            ->where('aps.cantidad', '>', 0)
            ->whereNull('aps.deleted_at')
            ->select([
                'a.id              as almacen_id',
                'a.almacen_id_externo',
                'a.nombre',
                'a.codigo_postal',
                'a.es_cd',
                'aps.id            as stock_id',
                'aps.cantidad',
            ])
            ->get();
    }

    private function descontarStockAlmacen(array $productos, string $claveAlmacen): void
    {
        foreach ($productos as $prod) {
            $actualizado = DB::table('almacen_producto_stock as aps')
                ->join('proveedor_almacenes as a', 'aps.proveedor_almacen_id', '=', 'a.id')
                ->join('proveedor_productos as pp', 'aps.proveedor_producto_id', '=', 'pp.id')
                ->join('proveedores as prov', 'pp.proveedor_id', '=', 'prov.id')
                ->where('prov.codigo_proveedor', 'cva')
                ->where('pp.codigo_proveedor', $prod['clave'])
                ->where('a.almacen_id_externo', $claveAlmacen)
                ->where('aps.cantidad', '>=', $prod['cantidad'])
                ->whereNull('aps.deleted_at')
                ->decrement('aps.cantidad', $prod['cantidad']);

            if (!$actualizado) {
                throw new CvaStockException(
                    "No se pudo descontar stock del almacén {$claveAlmacen} para {$prod['clave']}.",
                    500,
                );
            }
        }

        Log::info('Stock descontado en almacén', [
            'almacen_clave' => $claveAlmacen,
            'productos'     => collect($productos)->pluck('clave')->toArray(),
        ]);
    }

    private function descontarStockTotal(array $productos): void
    {
        foreach ($productos as $prod) {
            DB::table('proveedor_productos as pp')
                ->join('proveedores as prov', 'pp.proveedor_id', '=', 'prov.id')
                ->where('prov.codigo_proveedor', 'cva')
                ->where('pp.codigo_proveedor', $prod['clave'])
                ->where('pp.stock_total', '>=', $prod['cantidad'])
                ->decrement('pp.stock_total', $prod['cantidad']);
        }
    }

    private function obtenerStockPromocion(string $codigoProveedor, string $clavePromocion): ?int
    {
        try {
            $pp = ProveedorProducto::where('codigo_proveedor', $codigoProveedor)->first();
            if (!$pp) return null;

            return $pp->promociones()
                ->where('es_oferta', true)
                ->where('clave_promocion', $clavePromocion)
                ->value('disponible_en_promocion');

        } catch (\Exception $e) {
            Log::error('Error al obtener stock de promoción', [
                'codigo_proveedor' => $codigoProveedor,
                'clave_promocion'  => $clavePromocion,
                'error'            => $e->getMessage(),
            ]);
            return null;
        }
    }

    // =========================================================================
    // HELPERS — ENVÍO Y VALIDACIONES
    // =========================================================================

    private function mapearRespuestaOrden(array $response, array $flete, string $origen): array
    {
        $moneda = $response['moneda'] ?? 'USD';
        $precioProductos = (float) ($response['total'] ?? 0);
        $precioEnvio = (float) ($flete['montoTotal'] ?? 0);
        $monedaEnvio = $flete['moneda'] ?? 'MXN';
        $tipoCambio = TipoCambioMoneda::actual();

        // Convertir precios individuales a MXN
        $precioProductosMxn = (float) self::convertirAMxn((string) $precioProductos, $moneda, $tipoCambio);
        $precioEnvioMxn = (float) self::convertirAMxn((string) $precioEnvio, $monedaEnvio, $tipoCambio);

        return [
            'folio_pedido'                 => $response['pedido'],
            'iva_incluido'                 => (bool) ($response['iva'] ?? false),
            'precio_total_productos'       => $precioProductos,
            'moneda_cobro_productos'       => $moneda,
            'email_agente'                 => $response['email_agente'] ?? null,
            'email_almacen'                => $response['email_almacen'] ?? null,
            'precio_total_envio'           => $precioEnvio,
            'moneda_cobro_envio'           => $monedaEnvio,
            'origen_envio'                 => $origen,
            'envio_gratis'                 => $precioEnvio == 0,
            'fecha_entrega_estimada'       => $response['fecha_entrega_estimada'] ?? null,
            'status'                       => 'en_proceso',
            'tipo_cambio_aplicado'         => $moneda === 'MXN' ? null : $tipoCambio,
            'precio_total_productos_mxn'   => $precioProductosMxn,
            'precio_total_envio_mxn'       => $precioEnvioMxn,
            'precio_total_mxn'             => $precioProductosMxn + $precioEnvioMxn,
        ];
    }

    private static function convertirAMxn(string $precio, string $moneda, string $tipoCambio): string
    {
        if ($moneda === 'MXN') return $precio;

        return bcmul($precio, $tipoCambio, 4);
    }

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
            'calle'           => $cliente->calle            ?? '',
            'numero'          => $cliente->numero_exterior   ?? '',
            'numero_interior' => $cliente->numero_interior   ?? '',
            'cp'              => $cliente->codigo_postal     ?? '',
            'estado'          => (int) $estado->clave,
            'ciudad'          => (int) $ciudad->clave,
            'paqueteria'      => self::PAQUETERIAID,
            'atencion'        => $cliente->nombre            ?? '',
            'colonia'         => $cliente->colonia           ?? '',
            'atencion' => 'nxtit'
        ];

        return $datosEnvio ? array_merge($flete, $datosEnvio) : $flete;
    }

    // =========================================================================
    // INTERFAZ
    // =========================================================================

    public function codigoProveedor(): string
    {
        return 'cva';
    }

    public function obtenerEstatus(string $folioPedido): string
    {
        return 'pendiente';
    }

    public function cancelarPedido(string $folioPedido): bool
    {
        try {
            Log::info('Solicitando cancelación de pedido CVA', ['folio' => $folioPedido]);
            return true;
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