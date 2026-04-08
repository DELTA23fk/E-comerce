<?php

namespace App\Http\Resources\Admin;

use App\Enum\Order\ProveedorOrderStatusEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pedido de proveedor completo para el admin: precios en ambas monedas,
 * tipo de cambio, emails, flags de error y reembolso.
 */
class PedidoProveedorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = ProveedorOrderStatusEnum::tryFrom($this->status);
 
        return [
            'id'                   => $this->id,
            'folio_pedido'         => $this->folio_pedido,
            'status'               => $this->status,
            'status_label'         => $status?->label() ?? $this->status,
            'requiere_atencion'    => $this->requiere_atencion_manual,
            'error_mensaje'        => $this->error_mensaje,
            'error_detalle'        => $this->error_detalle,
 
            'proveedor' => [
                'id'     => $this->proveedor?->id,
                'nombre' => $this->proveedor?->nombre,
            ],
 
            'envio' => [
                'gratis'                 => $this->envio_gratis,
                'origen'                 => $this->origen_envio,
                'fecha_entrega_estimada' => $this->fecha_entrega_estimada?->toDateString(),
                'email_agente'           => $this->email_agente,
                'email_almacen'          => $this->email_almacen,
            ],
 
            'precios' => [
                'moneda_productos'       => $this->moneda_cobro_productos,
                'total_productos'        => $this->precio_total_productos,
                'moneda_envio'           => $this->moneda_cobro_envio,
                'total_envio'            => $this->precio_total_envio,
                'total'                  => $this->precio_total,
                'tipo_cambio'            => $this->tipo_cambio_aplicado,
                'iva_incluido'           => $this->iva_incluido,
                // En MXN
                'total_productos_mxn'    => $this->precio_total_productos_mxn,
                'total_envio_mxn'        => $this->precio_total_envio_mxn,
                'total_mxn'              => $this->precio_total_mxn,
            ],
 
            'reembolso' => [
                'monto_reembolsado_mxn' => $this->monto_reembolsado_mxn,
                'motivo'                => $this->motivo_reembolso,
                'fecha'                 => $this->fecha_reembolso?->toDateString(),
                'puede_reembolsarse'    => $this->puedeReembolsarse(),
                'monto_disponible'      => $this->montoDisponibleReembolso(),
            ],
 
            'productos' => AdminDetallePedidoResource::collection(
                $this->whenLoaded('detalles')
            ),
        ];
    }
}