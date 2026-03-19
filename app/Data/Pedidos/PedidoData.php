<?php

namespace App\Data\Pedidos;

use App\Data\Cva\ArticuloMinimoData;
use App\Enum\Payment\PaymentGetaway;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\Validation\Enum;
use Spatie\LaravelData\Attributes\Validation\Sometimes;
use Spatie\LaravelData\Attributes\Validation\StringType;
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
        #[Sometimes]
        public ?string $observaciones = null,
        #[StringType,Enum(PaymentGetaway::class)]
        public PaymentGetaway $metodoPago = PaymentGetaway::MERCADOPAGO->value
        
    )
    {
        
    }
}
