<?php

namespace App\Data\Seller;

use Spatie\LaravelData\Attributes\Validation\Digits;
use Spatie\LaravelData\Attributes\Validation\Exists;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Sometimes;
use Spatie\LaravelData\Data;

class RequestSearchClient extends Data
{
    /**
     * Create a new class instance.
     */
    public function __construct(
        #[Sometimes,Digits(10),Exists('clientes','telefono')]
        public string $telefono,
        #[Sometimes,Max(13),Min(12),Exists('clientes','rfc')]
        public string $rfc
    )
    {}
}
