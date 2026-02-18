<?php

namespace App\Data\Pedidos;

class ProductoPedido
{
    /**
     * Create a new class instance.
     */
    public function __construct(
        public readonly int $clave,
        public readonly int $cantidad
    )
    {
        //
    }
}
