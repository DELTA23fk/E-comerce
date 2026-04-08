<?php

namespace App\Http\Resources\Admin;

use App\Enum\Order\OrderStatusEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Tarjeta de pedido para el listado del admin.
 * Incluye datos del cliente, flags de atención y métricas de pago.
 */
class PedidoAdminResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                      => $this->id,
            'folio'                   => $this->folio,
            'fecha_pedido'            => $this->fecha_pedido->toDateString(),
            'estatus'                 => $this->estatus,
            'estatus_label'           => OrderStatusEnum::from($this->estatus)->label(),
            'requiere_atencion'       => $this->requiere_atencion_manual,
 
            'cliente' => [
                'id'       => $this->cliente?->id,
                'nombre'   => trim("{$this->cliente?->nombre} {$this->cliente?->apellidos}"),
                'telefono' => $this->cliente?->telefono,
            ],
 
            'pago' => [
                'gateway'          => $this->payment_gateway,
                'gateway_order_id' => $this->gateway_order_id,
                'estatus'          => $this->payment_status,
                'moneda'           => $this->moneda_cobro,
                'total_productos'  => $this->precio_total_productos,
                'total_envio'      => $this->precio_total_envio,
                'total'            => $this->precio_total,
                'monto_pagado'     => $this->monto_pagado,
                'monto_reembolsado'=> $this->monto_reembolsado,
                'fecha_pago'       => $this->fecha_pago?->toDateString(),
            ],
 
            'proveedores_resumen' => $this->whenLoaded('pedidosProveedores', fn() =>
                $this->pedidosProveedores->map(fn($pp) => [
                    'id'                     => $pp->id,
                    'status'                 => $pp->status,
                    'fecha_entrega_estimada' => $pp->fecha_entrega_estimada?->toDateString(),
                    'requiere_atencion'      => $pp->requiere_atencion_manual,
                ])
            ),
        ];
    }
}
