<?php

namespace App\Data\Producto;

readonly class AlmacenStockData
{
    public function __construct(
        public ?string $almacenIdExterno, // clave oficial del proveedor ("1", "46", "20")
        public string  $almacenNombre,    // nombre canónico normalizado
        public ?string $codigoPostal,
        public int     $cantidad,
        public bool    $esPrincipal  = false,
        public bool    $esCd         = false,
        public ?int    $backorder    = null,
        public ?string $etaBackorder = null,
    ) {}
}
