<?php

namespace App\Data\Pedidos;

use App\Data\Cva\ArticuloMinimoData;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

class PedidoData extends Data
{ 
    /**
     * Create a new class instance.
     */
    public function __construct(
         #[DataCollectionOf(ArticuloMinimoData::class)]
        public DataCollection $productos,
    )
    {
        
    }
}
