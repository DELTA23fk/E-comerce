<?php

namespace App\Data\Producto;

readonly class PromocionData
{
     public function __construct(
        public ?string $totalDescuento,
        public ?string $monedaDescuento,

        // Precio en moneda original del proveedor
        public ?string $monedaPrecioOriginal,
        public ?float  $precioConDescuento,

        // Equivalente MXN — null si ya venía en MXN
        public ?float  $precioConDescuentoMxn,
        public ?string $tipoCambioUsado,

        // Identificación
        public ?string $clavePromocion,
        public ?string $tipoDescuento,
        public ?string $descripcionPromocion,

        // Vigencia
        public ?string $fechaInicio,
        public ?string $expiracionFecha,   // fecha parseada Y-m-d
        public ?string $expiracionTexto,   // texto libre si no es fecha

        // Cantidades
        public ?int    $cantidadMinima,
        public ?int    $disponibleEnPromocion,

        public ?float  $precioRegular,
        public bool    $esOferta = false,
    ) {}
}
