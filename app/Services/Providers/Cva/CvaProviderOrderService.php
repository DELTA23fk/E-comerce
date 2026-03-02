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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CvaProviderOrderService implements ProveedorServiceInterface
{
    // Claves oficiales CVA — coinciden con almacenes.almacen_id_externo
    private const CLAVE_CEDIS_GDL    = '46';
    private const CLAVE_SUCURSAL_GDL = '1';
    private const PAQUETERIAID       = 4;
    private const CP_CEDIS_GDL       = 45640;
    private const CP_SUCURSAL_GDL    = 44900;

    // Claves de todos los CEDIS CVA
    private const CLAVES_CEDIS = ['46', '51', '54'];

    public function __construct(
        private CvaRepository $cvaRepository
    ) {}

    // =========================================================================
    // ENRIQUECIMIENTO
    // =========================================================================

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

            $promocionesInsuficientes = $proveedorProducto->promociones
                ->filter(fn($p) => $p->disponible_en_promocion > 0
                                && $p->disponible_en_promocion < $cantidadSolicitada);

            if ($promocionesInsuficientes->isNotEmpty()) {
                Log::info('Promoción no aplicada por stock insuficiente', [
                    'codigo_proveedor'    => $productoBasico['codigo_proveedor'],
                    'cantidad_solicitada' => $cantidadSolicitada,
                    'promociones'         => $promocionesInsuficientes->map(fn($p) => [
                        'clave'                    => $p->clave_promocion,
                        'stock_promo'              => $p->disponible_en_promocion,
                        'precio_con_descuento'     => $p->precio_con_descuento,
                        'precio_con_descuento_mxn' => $p->precio_con_descuento_mxn,
                        'moneda_original'          => $p->moneda_precio_original,
                    ])->toArray(),
                ]);
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
     * Valida stock suficiente para cubrir la demanda.
     *
     * Si se pasa $almacenPreferido, valida primero contra ese almacén.
     * Si no tiene stock suficiente se avisa en el log pero NO se lanza excepción
     * — la distribución se encargará de completar desde otros almacenes.
     * La excepción solo se lanza si el stock TOTAL (todos los almacenes) es insuficiente.
     *
     * @param string|int|null $almacenPreferido  clave externa ('1','46'...) o id interno
     */
    public function validarDisponibilidad(array $productos, string|int|null $almacenPreferido = null): bool
    {
        foreach ($productos as $producto) {
            $stockPorAlmacen = $this->obtenerStockPorAlmacen($producto->codigoProveedor);
            $stockTotal      = $stockPorAlmacen->sum('cantidad');

            if ($stockTotal < $producto->cantidad) {
                throw new CvaStockException(
                    "Stock insuficiente para {$producto->codigoProveedor}. " .
                    "Solicitado: {$producto->cantidad}, Disponible total: {$stockTotal}.",
                    400
                );
            }

            // Informar si el almacén preferido no tiene stock suficiente por sí solo
            if ($almacenPreferido !== null) {
                $almacen      = $this->resolverAlmacenPreferido($stockPorAlmacen, $almacenPreferido);
                $stockEnAlmacen = $almacen?->cantidad ?? 0;

                if ($stockEnAlmacen < $producto->cantidad) {
                    Log::info('Almacén preferido sin stock suficiente, se distribuirá', [
                        'codigo_proveedor'  => $producto->codigoProveedor,
                        'almacen_preferido' => $almacenPreferido,
                        'stock_en_almacen'  => $stockEnAlmacen,
                        'solicitado'        => $producto->cantidad,
                        'stock_total'       => $stockTotal,
                    ]);
                }
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
                        "Disponible en promoción: {$stockPromocion}.",
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

    /**
     * @param string|int|null $almacenPreferido  Clave externa CVA ('1','46'...) o id interno
     *                                            de la tabla almacenes. Cuando se especifica,
     *                                            la distribución intenta despachar desde ese
     *                                            almacén primero antes de buscar en otros.
     */
    public function crearPedido(
        PedidoProveedorRequestData $request,
        Cliente                    $cliente,
        string|int|null            $almacenPreferido = null,
    ): array {
        try {
            $this->validarAlcanceDeEnvio($cliente);

            $productosEnriquecidos = $this->enriquecerProductos($request->productos);
            $this->validarDisponibilidad($productosEnriquecidos, $almacenPreferido);

            $distribucion   = $this->distribucionStock($productosEnriquecidos, $almacenPreferido);
            $costoEnvioData = $this->calcularCostoEnvio($distribucion, $cliente);

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

                foreach ($distribucion['por_almacen'] as $clave => $grupo) {
                    if (empty($grupo['productos'])) continue;

                    $this->descontarStockAlmacen($grupo['productos'], $clave);
                    $this->descontarStockTotal($grupo['productos']);

                    $response     = $this->cvaRepository->crearOrden(array_merge($payloadBase, [
                        'codigo_sucursal' => (int) $clave,
                        'productos'       => $grupo['productos'],
                    ]));

                    $respuestas[] = $this->mapearRespuestaOrden(
                        $response,
                        $envios[$clave] ?? [],
                        $grupo['nombre']
                    );
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
            Log::error('Error al crear pedido CVA', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return ['success' => false, 'error' => 'Error al procesar pedido con CVA: ' . $e->getMessage()];
        }
    }

    /**
     * @param string|int|null $almacenPreferido  Igual que en crearPedido()
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
                ]
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
    // DISTRIBUCIÓN DE STOCK — núcleo del servicio
    // =========================================================================

    /**
     * Determina desde qué almacén(es) se despacha cada producto.
     *
     * Estrategia por producto (en orden de prioridad):
     *
     *   0. Almacén preferido (si se especificó) cubre toda la demanda → usar solo él
     *   1. CEDIS GDL principal (clave 46) cubre toda la demanda → usar solo él
     *   2. Otro CEDIS individual (51, 54) cubre toda la demanda → usar ese CEDIS
     *   3. Distribución: agotar CEDIS primero, luego sucursales
     *
     * El almacén preferido se resuelve por clave externa O por id interno.
     * Si no tiene stock suficiente por sí solo, se usa como punto de partida
     * en la distribución antes de buscar en otros.
     *
     * @param string|int|null $almacenPreferido
     */
    private function distribucionStock(
        array           $productos,
        string|int|null $almacenPreferido = null,
    ): array {
        $porAlmacen          = [];
        $detalleDistribucion = [];
        $requiereMultiples   = false;
        $almacenPreferidoInfo = null;

        foreach ($productos as $producto) {
            $stockPorAlmacen = $this->obtenerStockPorAlmacen($producto->codigoProveedor);
            $demanda         = $producto->cantidad;

            // ── 0. Almacén preferido cubre todo ──────────────────────────────
            if ($almacenPreferido !== null) {
                $almacenRow = $this->resolverAlmacenPreferido($stockPorAlmacen, $almacenPreferido);

                if ($almacenRow && $almacenRow->cantidad >= $demanda) {
                    $clave              = $almacenRow->almacen_id_externo;
                    $almacenPreferidoInfo ??= ['clave' => $clave, 'nombre' => $almacenRow->nombre];

                    $porAlmacen = $this->agregarAGrupo(
                        $porAlmacen,
                        $clave,
                        $almacenRow->nombre,
                        (bool) $almacenRow->es_cd,
                        (int) ($almacenRow->codigo_postal ?? 0),
                        $producto->codigoProveedor,
                        $demanda
                    );

                    $detalleDistribucion[] = $this->detalleDistribucion(
                        $producto,
                        [$clave => $demanda],
                        'preferido'
                    );
                    continue;
                }

                // Tiene stock parcial → úsalo primero y complementa abajo
                if ($almacenRow && $almacenRow->cantidad > 0) {
                    $almacenPreferidoInfo ??= [
                        'clave'  => $almacenRow->almacen_id_externo,
                        'nombre' => $almacenRow->nombre,
                    ];

                    $distribuido = $this->distribuirConPreferido(
                        $stockPorAlmacen,
                        $almacenRow,
                        $demanda,
                        $producto->codigoProveedor,
                        $porAlmacen
                    );

                    $porAlmacen          = $distribuido['por_almacen'];
                    $detalleDistribucion[] = $this->detalleDistribucion(
                        $producto,
                        $distribuido['asignado'],
                        'preferido-parcial'
                    );

                    if (count($distribuido['asignado']) > 1) {
                        $requiereMultiples = true;
                    }
                    continue;
                }

                // El almacén preferido no tiene nada → log y caer al flujo normal
                Log::info('Almacén preferido sin stock, usando flujo normal', [
                    'codigo_proveedor'  => $producto->codigoProveedor,
                    'almacen_preferido' => $almacenPreferido,
                ]);
            }

            // ── 1. CEDIS GDL principal cubre todo ────────────────────────────
            $cedisPrincipal = $stockPorAlmacen->firstWhere('almacen_id_externo', self::CLAVE_CEDIS_GDL);

            if ($cedisPrincipal && $cedisPrincipal->cantidad >= $demanda) {
                $porAlmacen = $this->agregarAGrupo(
                    $porAlmacen,
                    self::CLAVE_CEDIS_GDL,
                    $cedisPrincipal->nombre,
                    true,
                    self::CP_CEDIS_GDL,
                    $producto->codigoProveedor,
                    $demanda
                );

                $detalleDistribucion[] = $this->detalleDistribucion(
                    $producto,
                    [self::CLAVE_CEDIS_GDL => $demanda],
                    'cedis-principal'
                );
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
                    $porAlmacen,
                    $otroCedis->almacen_id_externo,
                    $otroCedis->nombre,
                    true,
                    (int) ($otroCedis->codigo_postal ?? 0),
                    $producto->codigoProveedor,
                    $demanda
                );

                $detalleDistribucion[] = $this->detalleDistribucion(
                    $producto,
                    [$otroCedis->almacen_id_externo => $demanda],
                    'otro-cedis'
                );
                continue;
            }

            // ── 3. Distribución general: CEDIS primero, luego sucursales ──────
            [$porAlmacen, $distribuido, $restante] = $this->distribuirGeneral(
                $stockPorAlmacen,
                $demanda,
                $producto->codigoProveedor,
                $porAlmacen
            );

            if ($restante > 0) {
                throw new CvaStockException(
                    "No hay stock suficiente en ningún almacén para {$producto->codigoProveedor}. " .
                    "Faltaron: {$restante} unidades.",
                    400
                );
            }

            if (count($distribuido) > 1) {
                $requiereMultiples = true;
            }

            $detalleDistribucion[] = $this->detalleDistribucion($producto, $distribuido, 'distribuido');
        }

        return [
            'por_almacen'               => $porAlmacen,
            'distribucion_productos'    => $detalleDistribucion,
            'requiere_envios_multiples' => $requiereMultiples,
            'almacen_preferido'         => $almacenPreferidoInfo,
        ];
    }

    /**
     * Distribuye empezando por el almacén preferido (stock parcial) y
     * completa con el flujo general si hace falta.
     *
     * @return array{por_almacen: array, asignado: array<string,int>}
     */
    private function distribuirConPreferido(
        Collection $stockPorAlmacen,
        object     $almacenRow,
        int        $demanda,
        string     $codigoProveedor,
        array      $porAlmacen,
    ): array {
        $asignado = [];
        $restante = $demanda;

        // Tomar todo lo que tiene el preferido
        $tomarPreferido                             = min($almacenRow->cantidad, $restante);
        $asignado[$almacenRow->almacen_id_externo]  = $tomarPreferido;
        $restante                                  -= $tomarPreferido;

        $porAlmacen = $this->agregarAGrupo(
            $porAlmacen,
            $almacenRow->almacen_id_externo,
            $almacenRow->nombre,
            (bool) $almacenRow->es_cd,
            (int) ($almacenRow->codigo_postal ?? 0),
            $codigoProveedor,
            $tomarPreferido
        );

        // Completar con el flujo general excluyendo el preferido
        if ($restante > 0) {
            $restanteStock = $stockPorAlmacen
                ->where('almacen_id_externo', '!=', $almacenRow->almacen_id_externo);

            [$porAlmacen, $distribuido, $restante] = $this->distribuirGeneral(
                $restanteStock,
                $restante,
                $codigoProveedor,
                $porAlmacen
            );

            $asignado = array_merge($asignado, $distribuido);

            if ($restante > 0) {
                throw new CvaStockException(
                    "No hay stock suficiente para {$codigoProveedor} incluso usando el almacén preferido. " .
                    "Faltaron: {$restante} unidades.",
                    400
                );
            }
        }

        return ['por_almacen' => $porAlmacen, 'asignado' => $asignado];
    }

    /**
     * Distribución estándar: agota CEDIS en orden descendente de stock,
     * luego sucursales. Devuelve el estado actualizado de $porAlmacen,
     * el mapa de asignaciones y las unidades que no se pudieron cubrir.
     *
     * @return array{0: array, 1: array<string,int>, 2: int}
     */
    private function distribuirGeneral(
        Collection $stockPorAlmacen,
        int        $demanda,
        string     $codigoProveedor,
        array      $porAlmacen,
    ): array {
        $distribuido = [];
        $restante    = $demanda;

        // Primero CEDIS
        foreach ($stockPorAlmacen->whereIn('almacen_id_externo', self::CLAVES_CEDIS)->sortByDesc('cantidad') as $a) {
            if ($restante <= 0 || $a->cantidad <= 0) continue;

            $tomar                              = min($a->cantidad, $restante);
            $distribuido[$a->almacen_id_externo] = ($distribuido[$a->almacen_id_externo] ?? 0) + $tomar;
            $restante                           -= $tomar;

            $porAlmacen = $this->agregarAGrupo(
                $porAlmacen, $a->almacen_id_externo, $a->nombre,
                true, (int) ($a->codigo_postal ?? 0), $codigoProveedor, $tomar
            );
        }

        // Luego sucursales
        if ($restante > 0) {
            foreach ($stockPorAlmacen->whereNotIn('almacen_id_externo', self::CLAVES_CEDIS)->sortByDesc('cantidad') as $a) {
                if ($restante <= 0 || $a->cantidad <= 0) continue;

                $tomar                              = min($a->cantidad, $restante);
                $distribuido[$a->almacen_id_externo] = ($distribuido[$a->almacen_id_externo] ?? 0) + $tomar;
                $restante                           -= $tomar;

                $porAlmacen = $this->agregarAGrupo(
                    $porAlmacen, $a->almacen_id_externo, $a->nombre,
                    false, (int) ($a->codigo_postal ?? 0), $codigoProveedor, $tomar
                );
            }
        }

        return [$porAlmacen, $distribuido, $restante];
    }

    /**
     * Resuelve el almacén preferido desde la colección de stock del producto.
     * Acepta clave externa ('1', '46'...) o id interno de la tabla almacenes.
     */
    private function resolverAlmacenPreferido(
        Collection      $stockPorAlmacen,
        string|int      $almacenPreferido,
    ): ?object {
        // Intentar por clave externa primero (string como '1', '46')
        $porClave = $stockPorAlmacen->firstWhere('almacen_id_externo', (string) $almacenPreferido);
        if ($porClave) return $porClave;

        // Intentar por id interno
        return $stockPorAlmacen->firstWhere('almacen_id', (int) $almacenPreferido) ?? null;
    }

    // =========================================================================
    // HELPERS — AGRUPACIÓN Y DETALLE
    // =========================================================================

    private function agregarAGrupo(
        array  $porAlmacen,
        string $clave,
        string $nombre,
        bool   $esCd,
        int    $cp,
        string $codigoProveedor,
        int    $cantidad
    ): array {
        if (!isset($porAlmacen[$clave])) {
            $porAlmacen[$clave] = [
                'nombre'    => $nombre,
                'es_cd'     => $esCd,
                'cp'        => $cp,
                'productos' => [],
            ];
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
            $porAlmacen[$clave]['productos'][] = [
                'clave'    => $codigoProveedor,
                'cantidad' => $cantidad,
            ];
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
            ->join('proveedor_almacenes as a', 'aps.proveedor_almacen_id', '=', 'a.id') // ← corregido
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
                    500
                );
            }
        }

        Log::info('Stock descontado en almacén', [
            'almacen_clave' => $claveAlmacen,
            'productos'     => collect($productos)->pluck('clave')->toArray(),
        ]);
    }

    /**
     * Descuenta el resumen stock_total en proveedor_productos.
     * Ya no distingue stock/stock_cd — el total es la única fuente de verdad
     * para compatibilidad con queries que no usan almacen_producto_stock.
     */
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

    private function descontarStockPromocion(string $codigoProveedor, int $cantidad): void
    {
        try {
            $pp = ProveedorProducto::where('codigo_proveedor', $codigoProveedor)->first();
            if (!$pp) return;

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
                    'codigo_proveedor'         => $codigoProveedor,
                    'clave_promocion'          => $promocionActiva->clave_promocion,
                    'cantidad'                 => $cantidad,
                    'precio_con_descuento_mxn' => $promocionActiva->precio_con_descuento_mxn,
                    'moneda_original'          => $promocionActiva->moneda_precio_original,
                ]);
            } else {
                Log::warning('No se pudo descontar stock de promoción', [
                    'codigo_proveedor'        => $codigoProveedor,
                    'cantidad_solicitada'     => $cantidad,
                    'disponible_en_promocion' => $promocionActiva->disponible_en_promocion,
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Error al descontar stock de promoción', [
                'codigo_proveedor' => $codigoProveedor,
                'error'            => $e->getMessage(),
            ]);
        }
    }

    private function precioEfectivoPromocion($promocion): float
    {
        return (float) ($promocion->precio_con_descuento_mxn
            ?? $promocion->precio_con_descuento
            ?? PHP_FLOAT_MAX);
    }

    // =========================================================================
    // HELPERS — ENVÍO Y VALIDACIONES
    // =========================================================================

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
            Log::error('Error al validar alcance de envío', [
                'cliente_id' => $cliente->id,
                'error'      => $e->getMessage(),
            ]);
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
            'calle'           => $cliente->calle           ?? '',
            'numero'          => $cliente->numero_exterior  ?? '',
            'numero_interior' => $cliente->numero_interior  ?? '',
            'cp'              => $cliente->codigo_postal    ?? '',
            'estado'          => (int) $estado->clave,
            'ciudad'          => (int) $ciudad->clave,
            'paqueteria'      => self::PAQUETERIAID,
            'atencion'        => $cliente->nombre           ?? '',
            'colonia'         => $cliente->colonia          ?? '',
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