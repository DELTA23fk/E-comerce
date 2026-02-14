<?php

namespace App\Data\Pedidos;

use Spatie\LaravelData\Data;

class PedidoProveedorRequestData extends Data
{
    /**
     * Create a new class instance.
     */
    public function __construct(
        public int $proveedorId,
        public string $numeroOrden,
        public array $productos, // ['clave' => 'HD-3235', 'cantidad' => 1]
        public ?array $datosEnvio = null,
        public bool $test = true,
        public int $cotiza_flete = 1,
        public string $observaciones = "Pedido de prueba generado vía API",

    )
    {
        //
    }
}
