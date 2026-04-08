<?php

namespace App\Http\Resources\Admin;

use App\Enum\Order\OrderStatusEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Detalle completo del pedido para el admin.
 * Expone todos los campos incluyendo errores, gateway IDs y datos internos.
 */
class PedidoAdminDetalleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                    => $this->id,
            'folio'                 => $this->folio,
            'fecha_pedido'          => $this->fecha_pedido->toDateString(),
            'observaciones'         => $this->observaciones,
            'estatus'               => $this->estatus,
            'estatus_label'         => OrderStatusEnum::from($this->estatus)->label(),
            'requiere_atencion'     => $this->requiere_atencion_manual,
            'errores'               => $this->errores_detallados,
 
            // ── Cliente completo ──
            'cliente' => [
                'id'               => $this->cliente?->id,
                'nombre'           => trim("{$this->cliente?->nombre} {$this->cliente?->apellidos}"),
                'telefono'         => $this->cliente?->telefono,
                'email'            => $this->cliente?->user?->email,
                'direccion'        => [
                    'calle'           => $this->cliente?->calle,
                    'numero_exterior' => $this->cliente?->numero_exterior,
                    'numero_interior' => $this->cliente?->numero_interior,
                    'colonia'         => $this->cliente?->colonia,
                    'ciudad'          => $this->cliente?->ciudad,
                    'estado'          => $this->cliente?->estado,
                    'codigo_postal'   => $this->cliente?->codigo_postal,
                    'referencias'     => $this->cliente?->referencias,
                ],
                'facturacion' => [
                    'razon_social' => $this->cliente?->razon_social,
                    'rfc'          => $this->cliente?->rfc,
                ],
            ],
 
            // ── Pago completo con datos del gateway ──
            'pago' => [
                'gateway'            => $this->payment_gateway,
                'gateway_order_id'   => $this->gateway_order_id,
                'gateway_payment_id' => $this->gateway_payment_id,
                'estatus'            => $this->payment_status,
                'moneda'             => $this->moneda_cobro,
                'total_productos'    => $this->precio_total_productos,
                'total_envio'        => $this->precio_total_envio,
                'total'              => $this->precio_total,
                'monto_pagado'       => $this->monto_pagado,
                'monto_reembolsado'  => $this->monto_reembolsado,
                'saldo_reembolsable' => $this->montoDisponibleReembolso(),
                'fecha_pago'         => $this->fecha_pago?->toDateString(),
            ],
 
            // ── Líneas de producto ──
            'productos' => AdminDetallePedidoResource::collection(
                $this->whenLoaded('detalles')
            ),
 
            // ── Pedidos por proveedor con todo el detalle ──
            'pedidos_proveedor' => PedidoProveedorResource::collection(
                $this->whenLoaded('pedidosProveedores')
            ),
 
            // ── Transacciones de pago ──
            'transacciones' => AdminTransaccionResource::collection(
                $this->whenLoaded('transacciones')
            ),
        ];
    }
}
