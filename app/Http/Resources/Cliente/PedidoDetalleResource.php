<?php

namespace App\Http\Resources\Cliente;

use App\Enum\Order\OrderStatusEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Detalle completo del pedido para el cliente.
 * Expone productos, desglose de precios y paquetes de envío.
 * Oculta: gateway IDs, datos de proveedor internos, tipo de cambio, emails de agente/almacén.
 */
class PedidoDetalleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'folio'        => $this->folio,
            'fecha_pedido' => $this->fecha_pedido->toDateString(),
            'observaciones'=> $this->observaciones,
            'estatus'      => $this->estatus,
            'estatus_label'=> OrderStatusEnum::from($this->estatus)->label(),
            'metodo_pago'   => $this->payment_gateway,
 
            // ── Resumen de pago (sin exponer IDs del gateway) ──
            'pago' => [
                'estatus'          => $this->payment_status,
                'moneda'           => $this->moneda_cobro,
                'total_productos'  => $this->precio_total_productos,
                'total_envio'      => $this->precio_total_envio,
                'total'            => $this->precio_total,
                'monto_pagado'     => $this->monto_pagado,
                'monto_reembolsado'=> $this->monto_reembolsado,
                'fecha_pago'       => $this->fecha_pago?->toDateString(),
            ],
 
            // ── Productos comprados ──
            'productos' => DetallePedidoResource::collection($this->whenLoaded('detalles')),
 
            // ── Paquetes de envío (uno por proveedor) ──
            'paquetes' => PaqueteEnvioResource::collection($this->whenLoaded('pedidosProveedores')),
        ];
    }
}
