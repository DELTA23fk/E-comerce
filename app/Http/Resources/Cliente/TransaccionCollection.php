<?php

namespace App\Http\Resources\Cliente;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class TransaccionCollection extends ResourceCollection
{
    public $collects = TransaccionResource::class;
 
    public function toArray(Request $request): array
    {
        return [
            'data' => $this->collection,
        ];
    }
}
