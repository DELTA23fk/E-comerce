<?php

namespace App\Services\Pedidos\admin;

use App\Data\Pedidos\PedidoFiltrosDTO;
use App\Enum\Order\OrderStatusEnum;
use App\Enum\Order\ProveedorOrderStatusEnum;
use App\Models\Pedido;
use App\Models\PedidoProveedor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PedidoService
{
    // ─────────────────────────────────────────────
    //  CONSULTA DE PEDIDOS (ADMIN)
    // ─────────────────────────────────────────────

    /**
     * Listado paginado con filtros avanzados para el panel de admin.
     */
    public function listarPedidos(PedidoFiltrosDTO $filtros): LengthAwarePaginator
    {
        return Pedido::query()
            ->with([
                'cliente:id,nombre,apellidos,telefono,user_id',
                'pedidosProveedores:id,pedido_id,proveedor_id,status,fecha_entrega_estimada,requiere_atencion_manual',
            ])
            ->when($filtros->estatus, fn($q) => $q->where('estatus', $filtros->estatus->value))
            ->when($filtros->folio, fn($q) => $q->where('folio', 'like', "%{$filtros->folio}%"))
            ->when($filtros->paymentStatus, fn($q) => $q->where('payment_status', $filtros->paymentStatus))
            ->when($filtros->fechaDesde, fn($q) => $q->whereDate('fecha_pedido', '>=', $filtros->fechaDesde))
            ->when($filtros->fechaHasta, fn($q) => $q->whereDate('fecha_pedido', '<=', $filtros->fechaHasta))
            ->orderBy($filtros->orderBy, $filtros->orderDir)
            ->paginate($filtros->perPage);
    }

    /**
     * Detalle completo de un pedido para el admin (sin restricción de cliente).
     */
    public function verDetallePedido(int $pedidoId): Pedido
    {
        return Pedido::with([
            'cliente.user:id,email',
            'detalles.producto',
            'detalles.pedidoProveedor:id,folio_pedido,status,proveedor_id,fecha_entrega_estimada',
            'pedidosProveedores.proveedor:id,nombre,email_contacto',
            'pedidosProveedores.detalles.producto',
            'transacciones',
        ])->findOrFail($pedidoId);
    }

    /**
     * Pedidos que requieren atención manual (flag activo en pedido o en pedido proveedor).
     */
    public function pedidosConAtencionRequerida(): Collection
    {
        return Pedido::query()
            ->with(['cliente:id,nombre,apellidos', 'pedidosProveedores:id,pedido_id,status,requiere_atencion_manual'])
            ->where(function ($q) {
                $q->where('requiere_atencion_manual', true)
                  ->orWhereHas('pedidosProveedores', fn($q2) => $q2->where('requiere_atencion_manual', true));
            })
            ->orderByDesc('updated_at')
            ->get();
    }

    /**
     * Pedidos con pago aprobado pero sin avanzar en estatus (posibles atascos).
     */
    public function pedidosAtascados(int $horasLimite = 24): Collection
    {
        return Pedido::query()
            ->with(['cliente:id,nombre,apellidos'])
            ->where('payment_status', 'approved')
            ->whereIn('estatus', [
                OrderStatusEnum::PENDIENTE->value,
                OrderStatusEnum::PROCESANDO->value,
            ])
            ->where('updated_at', '<', now()->subHours($horasLimite))
            ->orderBy('updated_at')
            ->get();
    }

    // ─────────────────────────────────────────────
    //  ACTUALIZACIÓN DE ESTADOS — PEDIDO MAESTRO
    // ─────────────────────────────────────────────

    /**
     * Actualiza el estatus del pedido maestro con validación de transición.
     *
     * @throws ValidationException
     */
    public function actualizarEstatusPedido(
        int    $pedidoId,
        string $nuevoEstatus,
        ?string $observaciones = null
    ): Pedido {
        $pedido   = Pedido::findOrFail($pedidoId);
        $actual   = OrderStatusEnum::from($pedido->estatus);
        $nuevo    = OrderStatusEnum::from($nuevoEstatus);

        $this->validarTransicionPedido($actual, $nuevo);

        $pedido->estatus = $nuevo->value;

        if ($observaciones !== null) {
            $pedido->observaciones = $observaciones;
        }

        $pedido->save();

        $this->invalidarCacheEstadisticas();

        return $pedido->fresh();
    }

    /**
     * Reglas de transición para el pedido maestro.
     *
     * @throws ValidationException
     */
    private function validarTransicionPedido(OrderStatusEnum $actual, OrderStatusEnum $nuevo): void
    {
        $permitidas = match($actual) {
            OrderStatusEnum::PENDIENTE_PAGO => [OrderStatusEnum::PENDIENTE, OrderStatusEnum::CANCELADO, OrderStatusEnum::FALLIDO],
            OrderStatusEnum::PENDIENTE      => [OrderStatusEnum::PROCESANDO, OrderStatusEnum::CANCELADO],
            OrderStatusEnum::PROCESANDO     => [OrderStatusEnum::PROCESADO, OrderStatusEnum::CANCELADO, OrderStatusEnum::FALLIDO],
            OrderStatusEnum::PROCESADO      => [OrderStatusEnum::ENVIADO],
            OrderStatusEnum::ENVIADO        => [OrderStatusEnum::ENTREGADO],
            OrderStatusEnum::ENTREGADO      => [],
            OrderStatusEnum::CANCELADO      => [],
            OrderStatusEnum::FALLIDO        => [OrderStatusEnum::PROCESANDO, OrderStatusEnum::CANCELADO],
        };

        if (!in_array($nuevo, $permitidas, true)) {
            throw ValidationException::withMessages([
                'estatus' => "No se puede cambiar de [{$actual->label()}] a [{$nuevo->label()}].",
            ]);
        }
    }

    // ─────────────────────────────────────────────
    //  ACTUALIZACIÓN DE ESTADOS — PEDIDO PROVEEDOR
    // ─────────────────────────────────────────────

    /**
     * Actualiza el status y/o fecha de entrega estimada de un PedidoProveedor.
     *
     * @throws ValidationException
     */
    public function actualizarPedidoProveedor(
        int     $pedidoProveedorId,
        ?string $nuevoStatus           = null,
        ?string $fechaEntregaEstimada  = null,
        ?string $emailAgente           = null,
        ?string $emailAlmacen          = null,
        ?string $origenEnvio           = null,
    ): PedidoProveedor {
        $pp = PedidoProveedor::with('pedidoMaestro')->findOrFail($pedidoProveedorId);

        DB::transaction(function () use ($pp, $nuevoStatus, $fechaEntregaEstimada, $emailAgente, $emailAlmacen, $origenEnvio) {

            if ($nuevoStatus !== null) {
                $actual = ProveedorOrderStatusEnum::from($pp->status);
                $nuevo  = ProveedorOrderStatusEnum::from($nuevoStatus);

                if (!$actual->puedeTransicionarA($nuevo)) {
                    throw ValidationException::withMessages([
                        'status' => "No se puede cambiar de [{$actual->label()}] a [{$nuevo->label()}].",
                    ]);
                }

                $pp->status = $nuevo->value;
            }

            if ($fechaEntregaEstimada !== null) {
                $pp->fecha_entrega_estimada = Carbon::parse($fechaEntregaEstimada)->toDateString();
            }

            if ($emailAgente !== null) {
                $pp->email_agente = $emailAgente;
            }

            if ($emailAlmacen !== null) {
                $pp->email_almacen = $emailAlmacen;
            }

            if ($origenEnvio !== null) {
                $pp->origen_envio = $origenEnvio;
            }

            $pp->save();

            // Sincroniza automáticamente el pedido maestro si todos los proveedores avanzaron
            $this->sincronizarEstatusMaestro($pp->pedidoMaestro);
        });

        return $pp->fresh(['pedidoMaestro', 'proveedor', 'detalles.producto']);
    }

    /**
     * Actualización masiva: aplica la misma fecha de entrega estimada a varios pedidos proveedor.
     */
    public function actualizarFechaEntregaMasiva(array $ids, string $fecha): int
    {
        $fechaCarbon = Carbon::parse($fecha)->toDateString();

        return PedidoProveedor::whereIn('id', $ids)->update([
            'fecha_entrega_estimada' => $fechaCarbon,
            'updated_at'             => now(),
        ]);
    }

    /**
     * Sincroniza el estatus del pedido maestro basándose en el estado de sus proveedores.
     * Lógica: el pedido maestro avanza cuando TODOS sus proveedores alcanzan cierto estado.
     */
    private function sincronizarEstatusMaestro(Pedido $pedido): void
    {
        $pedido->loadMissing('pedidosProveedores');

        $statuses = $pedido->pedidosProveedores->pluck('status');

        if ($statuses->isEmpty()) {
            return;
        }

        $todosEntregados = $statuses->every(fn($s) => $s === ProveedorOrderStatusEnum::ENTREGADO->value);
        $todosEnviados   = $statuses->every(fn($s) => in_array($s, [
            ProveedorOrderStatusEnum::ENVIADO->value,
            ProveedorOrderStatusEnum::ENTREGADO->value,
        ]));
        $todosProcesados = $statuses->every(fn($s) => in_array($s, [
            ProveedorOrderStatusEnum::PROCESADO->value,
            ProveedorOrderStatusEnum::ENVIADO->value,
            ProveedorOrderStatusEnum::ENTREGADO->value,
        ]));

        $nuevoEstatus = match(true) {
            $todosEntregados => OrderStatusEnum::ENTREGADO->value,
            $todosEnviados   => OrderStatusEnum::ENVIADO->value,
            $todosProcesados => OrderStatusEnum::PROCESADO->value,
            default          => null,
        };

        if ($nuevoEstatus && $pedido->estatus !== $nuevoEstatus) {
            $pedido->estatus = $nuevoEstatus;
            $pedido->save();
        }
    }

    // ─────────────────────────────────────────────
    //  ESTADÍSTICAS GENERALES (ADMIN)
    // ─────────────────────────────────────────────

    /**
     * Dashboard principal: métricas globales con cache de 5 minutos.
     */
    public function estadisticasGenerales(): array
    {
        return Cache::remember('admin_estadisticas_generales', now()->addMinutes(5), function () {

            // ── Totales globales ──
            $global = DB::table('pedidos')
                ->whereNull('deleted_at')
                ->selectRaw('
                    COUNT(*) as total_pedidos,
                    COALESCE(SUM(precio_total), 0) as ingresos_brutos,
                    COALESCE(SUM(monto_pagado), 0) as total_cobrado,
                    COALESCE(SUM(monto_reembolsado), 0) as total_reembolsado,
                    COALESCE(AVG(precio_total), 0) as ticket_promedio,
                    COALESCE(MAX(precio_total), 0) as pedido_mayor,
                    COUNT(DISTINCT cliente_id) as clientes_unicos
                ')
                ->first();

            // ── Por estatus ──
            $porEstatus = DB::table('pedidos')
                ->whereNull('deleted_at')
                ->selectRaw('estatus, COUNT(*) as total, COALESCE(SUM(precio_total), 0) as suma')
                ->groupBy('estatus')
                ->get()
                ->keyBy('estatus');

            // ── Por payment_status ──
            $porPayment = DB::table('pedidos')
                ->whereNull('deleted_at')
                ->selectRaw('payment_status, COUNT(*) as total')
                ->groupBy('payment_status')
                ->pluck('total', 'payment_status');

            // ── Pedidos hoy / esta semana / este mes ──
            $periodos = DB::table('pedidos')
                ->whereNull('deleted_at')
                ->selectRaw("
                    COUNT(CASE WHEN DATE(fecha_pedido) = CURDATE() THEN 1 END) as hoy,
                    COUNT(CASE WHEN fecha_pedido >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 END) as ultima_semana,
                    COUNT(CASE WHEN MONTH(fecha_pedido) = MONTH(CURDATE()) AND YEAR(fecha_pedido) = YEAR(CURDATE()) THEN 1 END) as este_mes,
                    COALESCE(SUM(CASE WHEN MONTH(fecha_pedido) = MONTH(CURDATE()) AND YEAR(fecha_pedido) = YEAR(CURDATE()) THEN precio_total END), 0) as ingresos_mes
                ")
                ->first();

            // ── Requieren atención ──
            $atencion = DB::table('pedidos')
                ->whereNull('deleted_at')
                ->where('requiere_atencion_manual', true)
                ->count();

            $atencionProveedores = DB::table('pedido_proveedores')
                ->whereNull('deleted_at')
                ->where('requiere_atencion_manual', true)
                ->count();

            return [
                'global' => [
                    'total_pedidos'      => (int) $global->total_pedidos,
                    'ingresos_brutos'    => round((float) $global->ingresos_brutos, 2),
                    'total_cobrado'      => round((float) $global->total_cobrado, 2),
                    'total_reembolsado'  => round((float) $global->total_reembolsado, 2),
                    'neto_cobrado'       => round((float) $global->total_cobrado - (float) $global->total_reembolsado, 2),
                    'ticket_promedio'    => round((float) $global->ticket_promedio, 2),
                    'pedido_mayor'       => round((float) $global->pedido_mayor, 2),
                    'clientes_unicos'    => (int) $global->clientes_unicos,
                ],
                'periodos' => [
                    'pedidos_hoy'        => (int) $periodos->hoy,
                    'pedidos_7_dias'     => (int) $periodos->ultima_semana,
                    'pedidos_mes'        => (int) $periodos->este_mes,
                    'ingresos_mes'       => round((float) $periodos->ingresos_mes, 2),
                ],
                'por_estatus'           => $this->mapearPorEstatus($porEstatus),
                'por_payment_status'    => $porPayment,
                'atencion_manual'       => [
                    'pedidos'            => $atencion,
                    'pedidos_proveedor'  => $atencionProveedores,
                    'total'              => $atencion + $atencionProveedores,
                ],
            ];
        });
    }

    /**
     * Tendencia mensual de pedidos e ingresos (últimos N meses).
     */
    public function tendenciaMensual(int $meses = 12): Collection
    {
        $cacheKey = "admin_tendencia_mensual_{$meses}";

        return Cache::remember($cacheKey, now()->addMinutes(15), function () use ($meses) {
            return DB::table('pedidos')
                ->whereNull('deleted_at')
                ->where('fecha_pedido', '>=', now()->subMonths($meses)->startOfMonth())
                ->selectRaw("
                    DATE_FORMAT(fecha_pedido, '%Y-%m') as periodo,
                    COUNT(*) as total_pedidos,
                    COALESCE(SUM(precio_total), 0) as ingresos,
                    COALESCE(SUM(monto_pagado), 0) as cobrado,
                    COALESCE(AVG(precio_total), 0) as ticket_promedio,
                    COUNT(DISTINCT cliente_id) as clientes
                ")
                ->groupByRaw("DATE_FORMAT(fecha_pedido, '%Y-%m')")
                ->orderBy('periodo')
                ->get();
        });
    }

    /**
     * Estadísticas de pedidos proveedor: estados, fechas, atrasos.
     */
    public function estadisticasProveedores(): array
    {
        return Cache::remember('admin_estadisticas_proveedores', now()->addMinutes(10), function () {

            $porStatus = DB::table('pedido_proveedores')
                ->whereNull('deleted_at')
                ->selectRaw('status, COUNT(*) as total, COALESCE(SUM(precio_total_mxn), 0) as suma_mxn')
                ->groupBy('status')
                ->get()
                ->keyBy('status');

            // PedidosProveedor que ya pasaron su fecha estimada y no están entregados
            $atrasados = DB::table('pedido_proveedores')
                ->whereNull('deleted_at')
                ->whereNotNull('fecha_entrega_estimada')
                ->whereNotIn('status', [
                    ProveedorOrderStatusEnum::ENTREGADO->value,
                    ProveedorOrderStatusEnum::CANCELADO->value,
                ])
                ->whereDate('fecha_entrega_estimada', '<', now()->toDateString())
                ->count();

            // Próximos a entregar (siguientes 3 días)
            $proximosEntrega = DB::table('pedido_proveedores')
                ->whereNull('deleted_at')
                ->whereNotNull('fecha_entrega_estimada')
                ->whereIn('status', [
                    ProveedorOrderStatusEnum::ENVIADO->value,
                    ProveedorOrderStatusEnum::PROCESADO->value,
                ])
                ->whereBetween('fecha_entrega_estimada', [
                    now()->toDateString(),
                    now()->addDays(3)->toDateString(),
                ])
                ->count();

            $general = DB::table('pedido_proveedores')
                ->whereNull('deleted_at')
                ->selectRaw('
                    COUNT(*) as total,
                    COALESCE(SUM(precio_total_mxn), 0) as valor_total_mxn,
                    COALESCE(SUM(monto_reembolsado_mxn), 0) as total_reembolsado_mxn,
                    COUNT(CASE WHEN envio_gratis = 1 THEN 1 END) as con_envio_gratis,
                    COUNT(DISTINCT proveedor_id) as proveedores_activos
                ')
                ->first();

            return [
                'general' => [
                    'total'                 => (int) $general->total,
                    'valor_total_mxn'       => round((float) $general->valor_total_mxn, 2),
                    'total_reembolsado_mxn' => round((float) $general->total_reembolsado_mxn, 2),
                    'con_envio_gratis'      => (int) $general->con_envio_gratis,
                    'proveedores_activos'   => (int) $general->proveedores_activos,
                ],
                'por_status'       => $porStatus->map(fn($r) => [
                    'total'     => (int) $r->total,
                    'suma_mxn'  => round((float) $r->suma_mxn, 2),
                ])->toArray(),
                'alertas' => [
                    'atrasados'        => $atrasados,
                    'proximos_entrega' => $proximosEntrega,
                ],
            ];
        });
    }

    /**
     * Ranking de pedidos por cliente (top N clientes por gasto).
     */
    public function topClientes(int $limite = 10): Collection
    {
        return Cache::remember("admin_top_clientes_{$limite}", now()->addMinutes(15), function () use ($limite) {
            return DB::table('pedidos')
                ->join('clientes', 'pedidos.cliente_id', '=', 'clientes.id')
                ->whereNull('pedidos.deleted_at')
                ->selectRaw('
                    clientes.id,
                    clientes.nombre,
                    clientes.apellidos,
                    COUNT(pedidos.id) as total_pedidos,
                    COALESCE(SUM(pedidos.precio_total), 0) as gasto_total,
                    COALESCE(AVG(pedidos.precio_total), 0) as ticket_promedio,
                    MAX(pedidos.fecha_pedido) as ultimo_pedido
                ')
                ->groupBy('clientes.id', 'clientes.nombre', 'clientes.apellidos')
                ->orderByDesc('gasto_total')
                ->limit($limite)
                ->get();
        });
    }

    // ─────────────────────────────────────────────
    //  UTILIDADES
    // ─────────────────────────────────────────────

    /**
     * Mapea resultados de DB a labels legibles por estado.
     */
    private function mapearPorEstatus($porEstatus): array
    {
        $resultado = [];

        foreach (OrderStatusEnum::cases() as $case) {
            $fila = $porEstatus->get($case->value);
            $resultado[$case->value] = [
                'label' => $case->label(),
                'total' => $fila ? (int) $fila->total : 0,
                'suma'  => $fila ? round((float) $fila->suma, 2) : 0.0,
            ];
        }

        return $resultado;
    }

    /**
     * Invalida toda la caché de estadísticas del admin.
     */
    public function invalidarCacheEstadisticas(): void
    {
        Cache::forget('admin_estadisticas_generales');
        Cache::forget('admin_estadisticas_proveedores');
        Cache::forget('admin_tendencia_mensual_12');
        Cache::forget('admin_top_clientes_10');
    }
}