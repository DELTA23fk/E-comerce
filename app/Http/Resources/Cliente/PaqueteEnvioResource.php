<?php

namespace App\Http\Resources\Cliente;

use App\Enum\Order\ProveedorOrderStatusEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Representa un paquete de envío dentro del pedido del cliente.
 * El cliente NO necesita saber el nombre del proveedor, emails internos,
 * tipo de cambio ni precios en moneda extranjera.
 */
class PaqueteEnvioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = ProveedorOrderStatusEnum::tryFrom($this->status);
 
        return [
            'paquete_id'             => $this->id,
            'estatus'                => $this->status,
            'estatus_label'          => $status?->label() ?? $this->status,
            'origen_envio'           => $this->origen_envio,
            'envio_gratis'           => $this->envio_gratis,
            'fecha_entrega_estimada' => $this->fecha_entrega_estimada?->toDateString(),
            'costo_envio'            => $this->envio_gratis ? 0 : $this->precio_total_envio_mxn,
            'productos'              => DetallePedidoResource::collection($this->whenLoaded('detalles')),
        ];
    }
}
 
