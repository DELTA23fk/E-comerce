<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class PedidoAdminCollection extends ResourceCollection
{
    public $collects = PedidoAdminResource::class;
 
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection,
        ];
    }
}
