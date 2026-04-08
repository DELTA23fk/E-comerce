<?php

namespace App\Http\Resources\Cliente;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class PedidoCollection extends ResourceCollection
{
    public $collects = PedidoResource::class;
 
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection,
        ];
    }
}