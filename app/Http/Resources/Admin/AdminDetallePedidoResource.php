<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Línea de producto con clave interna del proveedor, visible solo para admin.
 */
class AdminDetallePedidoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'producto_id'         => $this->proveedor_producto_id,
            'nombre'              => $this->producto?->nombre ?? 'Producto',
            'clave_proveedor'     => $this->clave_proveedor,
            'cantidad'            => $this->cantidad,
            'precio_unitario'     => $this->precio_unitario,
            'subtotal'            => $this->subtotal,
            'pedido_proveedor_id' => $this->pedido_proveedor_id,
        ];
    }
}
