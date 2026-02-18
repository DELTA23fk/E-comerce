<?php

namespace App\Data\Request;

use App\Data\Cva\ArticuloMinimoData;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\Validation\Digits;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

class RequestCotizarcionFleteCvaData extends Data
{
    /**
     * Create a new class instance.
     */
    public function __construct(
        public ?int $paqueteria,
        #[Digits(5),MapInputName("cpDestino")]
        public int $cp,
        #[Digits(5)]
        public ?int $cp_sucursal,
        #[MapInputName("productos"),DataCollectionOf(ArticuloMinimoData::class)]
        public DataCollection $productos
    )
    {
        //
    }
}
