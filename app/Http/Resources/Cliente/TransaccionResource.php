<?php

namespace App\Http\Resources\Cliente;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transacción de pago visible para el cliente.
 * Sin referencia interna del gateway ni datos de auditoría.
 */
class TransaccionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'fecha'         => $this->resource['fecha_pago']?->toDateTimeString(),
            'monto'         => $this->resource['monto'],
            'estatus'       => $this->resource['estatus'],
            'metodo_pago'   => $this->resource['gateway'],
        ];
    }
}
