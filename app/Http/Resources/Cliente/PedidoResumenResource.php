<?php

namespace App\Http\Resources\Cliente;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Vista ejecutiva del pedido: solo lo necesario para una tarjeta de resumen.
 */
class PedidoResumenResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'folio'                   => $this->folio,
            'estatus'                 => $this->estatus,
            'estatus_label'           => $this->estatus_label,
            'pago'                    => [
                'estatus' => $this->payment_status,
                'total'   => $this->precio_total,
                'moneda'  => $this->moneda,
            ],
            'entrega' => [
                'mas_pronto'      => $this->fecha_entrega_mas_pronto,
                'mas_tarde'       => $this->fecha_entrega_mas_tarde,
                'total_paquetes'  => $this->total_proveedores,
            ],
            'fecha_pedido'            => $this->fecha_pedido,
            'requiere_atencion'       => $this->requiere_atencion,
        ];
    }
}
 
