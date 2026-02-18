<?php

namespace App\Data\Pedidos;

use Spatie\LaravelData\Data;

class PedidoProveedorResponseData extends Data
{
    /**
     * Create a new class instance.
     */
    public function __construct(
        public bool $success,
        public ?string $folioPedido = null,
        public ?float $subtotal = null,
        public ?float $iva = null,
        public ?float $total = null,
        public ?string $moneda = null,
        public ?string $emailAgente = null,
        public ?string $emailAlmacen = null,
        public ?array $flete = null,
        public ?string $error = null
    )
    {
        //
    }
}
