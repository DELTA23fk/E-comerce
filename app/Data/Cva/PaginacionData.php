<?php

namespace App\Data\Cva;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapInputName(SnakeCaseMapper::class)]
class PaginacionData extends Data
{
    public function __construct(
        public ?int $totalPaginas,
        public ?int $pagina,
    ) {}
}
