<?php

namespace App\Data\Response;

use App\Data\Cva\ArticuloData;
use App\Data\Cva\PaginacionData;
use App\Data\Cva\PromocionCvaData;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapInputName(SnakeCaseMapper::class)]
class ApiCvaResponse extends Data
{
    /**
     * Create a new class instance.
     */
    public function __construct(
        #[DataCollectionOf(ArticuloData::class)]
        public DataCollection $articulos,
        public ?PaginacionData $paginacion
    )
    {
        //
    }

}
