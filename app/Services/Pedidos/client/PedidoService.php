<?php

namespace App\Services\Pedidos\client;

use App\Data\Pedidos\PedidoFiltrosDTO;
use App\Enum\Order\OrderStatusEnum;
use App\Models\Pedido;
use App\Models\Cliente;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class PedidoService
{
    // ─────────────────────────────────────────────
    //  CONSULTA DE PEDIDOS
    // ─────────────────────────────────────────────

    /**
     * Lista paginada de pedidos del cliente con filtros opcionales.
     */
    public function listarPedidos(
        Cliente $cliente,
        PedidoFiltrosDTO $filtros
    ): LengthAwarePaginator {
        $query = Pedido::query()
            ->where('cliente_id', $cliente->id)
            ->with([
                'pedidosProveedores:id,pedido_id,proveedor_id,status,fecha_entrega_estimada,precio_total_mxn',
            ])
            ->when($filtros->estatus, fn($q) => $q->where('estatus', $filtros->estatus->value))
            ->when($filtros->folio, fn($q) => $q->where('folio', 'like', "%{$filtros->folio}%"))
            ->when($filtros->paymentStatus, fn($q) => $q->where('payment_status', $filtros->paymentStatus))
            ->when(
                $filtros->fechaDesde,
                fn($q) => $q->whereDate('fecha_pedido', '>=', $filtros->fechaDesde)
            )
            ->when(
                $filtros->fechaHasta,
                fn($q) => $q->whereDate('fecha_pedido', '<=', $filtros->fechaHasta)
            )
            ->orderBy($filtros->orderBy, $filtros->orderDir);

        return $query->paginate($filtros->perPage);
    }

    /**
     * Detalle completo de un pedido: valida que pertenezca al cliente.
     */
    public function verDetallePedido(Cliente $cliente, int $pedidoId): Pedido
    {
        return Pedido::query()
            ->where('cliente_id', $cliente->id)
            ->with([
                'detalles.producto',
                'detalles.pedidoProveedor:id,folio_pedido,status,fecha_entrega_estimada,origen_envio',
                'pedidosProveedores.proveedor:id,nombre',
                'pedidosProveedores.detalles.producto',
                'transacciones:id,pedido_id,monto,status,gateway',
            ])
            ->findOrFail($pedidoId);
    }

    /**
     * Resumen ejecutivo de un pedido para vista rápida (sin cargar todo).
     */
    public function resumenPedido(Cliente $cliente, int $pedidoId): array
    {
        $pedido = Pedido::query()
            ->where('cliente_id', $cliente->id)
            ->with(['pedidosProveedores:id,pedido_id,status,fecha_entrega_estimada'])
            ->findOrFail($pedidoId);

        $fechasEstimadas = $pedido->pedidosProveedores
            ->whereNotNull('fecha_entrega_estimada')
            ->pluck('fecha_entrega_estimada');

        return [
            'folio'                    => $pedido->folio,
            'estatus'                  => $pedido->estatus,
            'estatus_label'            => OrderStatusEnum::from($pedido->estatus)->label(),
            'payment_status'           => $pedido->payment_status,
            'fecha_pedido'             => $pedido->fecha_pedido->toDateString(),
            'precio_total'             => $pedido->precio_total,
            'moneda'                   => $pedido->moneda_cobro,
            'fecha_entrega_mas_tarde'  => $fechasEstimadas->max()?->toDateString(),
            'fecha_entrega_mas_pronto' => $fechasEstimadas->min()?->toDateString(),
            'total_proveedores'        => $pedido->pedidosProveedores->count(),
            'requiere_atencion'        => $pedido->requiere_atencion_manual,
        ];
    }

    /**
     * Seguimiento de envío: muestra estado por proveedor con fecha estimada.
     */
    public function seguimientoEnvio(Cliente $cliente, int $pedidoId): array
    {
        $pedido = Pedido::query()
            ->where('cliente_id', $cliente->id)
            ->with([
                'pedidosProveedores:id,pedido_id,proveedor_id,folio_pedido,status,fecha_entrega_estimada,origen_envio,envio_gratis',
                'pedidosProveedores.proveedor:id,nombre',
            ])
            ->findOrFail($pedidoId);

        $seguimiento = $pedido->pedidosProveedores->map(fn($pp) => [
            'folio_proveedor'       => $pp->folio_pedido,
            'proveedor'             => $pp->proveedor?->nombre,
            'origen_envio'          => $pp->origen_envio,
            'status'                => $pp->status,
            'fecha_entrega_estimada'=> $pp->fecha_entrega_estimada?->toDateString(),
            'envio_gratis'          => $pp->envio_gratis,
        ]);

        return [
            'folio_pedido' => $pedido->folio,
            'estatus'      => $pedido->estatus,
            'proveedores'  => $seguimiento->values()->toArray(),
        ];
    }

    /**
     * Historial de transacciones de pago del cliente en un pedido.
     */
    public function transaccionesPedido(Cliente $cliente, int $pedidoId): ?Pedido
    {
        $pedido = Pedido::query()
            ->with(['transacciones:id,pedido_id,monto,status,gateway'])
            ->where('cliente_id', $cliente->id)
            ->where('id', $pedidoId)
            ->first();
        

        return $pedido ?? null;
    }

    // ─────────────────────────────────────────────
    //  ESTADÍSTICAS PERSONALES DEL CLIENTE
    // ─────────────────────────────────────────────

    /**
     * Estadísticas generales del cliente sobre sus propios pedidos.
     * Se cachean 10 min para evitar queries pesadas en cada request.
     */
    public function estadisticasCliente(Cliente $cliente): array
    {
        $cacheKey = "estadisticas_cliente_{$cliente->id}";

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($cliente) {
            $base = Pedido::query()->where('cliente_id', $cliente->id);

            $porEstatus = (clone $base)
                ->selectRaw('estatus, COUNT(*) as total, SUM(precio_total) as suma')
                ->groupBy('estatus')
                ->get()
                ->keyBy('estatus');

            $general = (clone $base)
                ->selectRaw('
                    COUNT(*) as total_pedidos,
                    COALESCE(SUM(precio_total), 0) as gasto_total,
                    COALESCE(AVG(precio_total), 0) as ticket_promedio,
                    COALESCE(SUM(monto_reembolsado), 0) as total_reembolsado,
                    COALESCE(MAX(precio_total), 0) as pedido_mayor
                ')
                ->first();

            $ultimoPedido = (clone $base)
                ->latest('fecha_pedido')
                ->value('fecha_pedido');

            return [
                'total_pedidos'      => (int) $general->total_pedidos,
                'gasto_total'        => round((float) $general->gasto_total, 2),
                'ticket_promedio'    => round((float) $general->ticket_promedio, 2),
                'total_reembolsado'  => round((float) $general->total_reembolsado, 2),
                'pedido_mayor'       => round((float) $general->pedido_mayor, 2),
                'ultimo_pedido'      => $ultimoPedido?->toDateString(),
                'por_estatus'        => $porEstatus->map(fn($r) => [
                    'total' => (int) $r->total,
                    'suma'  => round((float) $r->suma, 2),
                ])->toArray(),
                'pedidos_activos'    => (int) ($porEstatus->get(OrderStatusEnum::PROCESANDO->value)?->total ?? 0)
                                      + (int) ($porEstatus->get(OrderStatusEnum::ENVIADO->value)?->total ?? 0),
            ];
        });
    }

    /**
     * Limpia la caché de estadísticas del cliente (llamar tras actualizar).
     */
    public function invalidarCacheCliente(Cliente $cliente): void
    {
        Cache::forget("estadisticas_cliente_{$cliente->id}");
    }
}