<?php

namespace App\Data\Cva;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Numeric;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

class ArticuloMinimoData extends Data
{
    /**
     * Create a new class instance.
     */
    public function __construct(
       
        #[MapInputName("claveProveedorProducto")]
        public string $clave,
        #[IntegerType,Min(1)]
        public int $cantidad
    )
    {
    }
}
