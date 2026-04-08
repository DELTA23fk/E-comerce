<?php

namespace App\Http\Resources\Cliente;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Seguimiento de envío por paquete.
 * No expone folio del proveedor ni nombre del proveedor.
 */
class SeguimientoEnvioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $paquetes = collect($this->proveedores)->map(fn($p) => [
            'paquete_numero'         => null, // se asigna abajo con index
            'estatus'                => $p['status'],
            'origen_envio'           => $p['origen_envio'],
            'fecha_entrega_estimada' => $p['fecha_entrega_estimada'],
            'envio_gratis'           => $p['envio_gratis'],
        ])->values()->map(fn($p, $i) => array_merge($p, ['paquete_numero' => $i + 1]));
 
        return [
            'folio_pedido' => $this->folio_pedido,
            'estatus'      => $this->estatus,
            'paquetes'     => $paquetes,
        ];
    }
}
