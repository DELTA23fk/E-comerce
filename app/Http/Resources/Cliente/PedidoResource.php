<?php

namespace App\Http\Resources\Cliente;

use App\Enum\Order\OrderStatusEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Vista de tarjeta: lo justo para mostrar en el listado de "Mis pedidos".
 * No expone gateway, IDs internos ni datos de proveedor sensibles.
 */
class PedidoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $fechasEstimadas = $this->pedidosProveedores
            ->whereNotNull('fecha_entrega_estimada')
            ->pluck('fecha_entrega_estimada');
 
        return [
            'id'                    => $this->id,
            'folio'                 => $this->folio,
            'fecha_pedido'          => $this->fecha_pedido->toDateString(),
            'estatus'               => $this->estatus,
            'estatus_label'         => OrderStatusEnum::from($this->estatus)->label(),
            'pago'                  => [
                'estatus'  => $this->payment_status,
                'moneda'   => $this->moneda_cobro,
                'total'    => $this->precio_total,
            ],
            'entrega' => [
                'fecha_estimada_pronto'  => $fechasEstimadas->min()?->toDateString(),
                'fecha_estimada_tarde'   => $fechasEstimadas->max()?->toDateString(),
                'total_paquetes'         => $this->pedidosProveedores->count(),
            ],
        ];
    }
}