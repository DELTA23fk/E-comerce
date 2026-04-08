<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transacción completa para auditoría interna.
 */
class AdminTransaccionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'monto'             => $this->monto,
            'estatus'           => $this->estatus,
            'metodo_pago'       => $this->metodo_pago,
            'referencia'        => $this->referencia,
            'fecha_transaccion' => $this->fecha_transaccion,
        ];
    }
}