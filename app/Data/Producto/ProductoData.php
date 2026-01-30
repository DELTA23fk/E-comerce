<?php

namespace App\Data\Producto;

readonly class ProductoData
{
    // CLASE ESTANDAR PARA LA CREACION DE PRODUTOS Y SUS RELACIONES
    public function __construct(
         // Datos básicos de 'products'
        public string $nombre,
        public string $descripcion,
        public ?string $descripcionTecnica,
        public ?string $codigoFabricante,
        public ?string $codigoBarras,
        public ?string $upc,
        
        // Relaciones (nombres para buscar IDs después)
        public ?string $categoriaNombre,
        public ?string $subcategoriaNombre,
        public ?string $familiaNombre,
        public ?string $grupoNombre,
        public ?string $marcaNombre,

        // Datos para 'provider_products'
        public  string $proveedorProductoId, // El 'id' que viene de la API
        public  string $proveedorProductoCodigo, // El 'sku' o 'clave'
        public  string $moneda,
        public  int $stock,
        public  ?int $stockCD,
        public  bool $enOferta,
        public  ?string $garantia,
        
       // NOTA: uso de string para presicion de precios , se hace uso de bcadd
        // Datos para 'provider_product_prices'
        public  string $precioActual, 
        public  ?string $precioAnterior = null,

        // Imágenes
        public  array $imagenes = [],

        // Promociones
        public ?string $descuentoTotal = null,
        public ?string $descuentoMoneda = null,
        public ?string $descuentoPrecio = null,
        public ?string $descuentoPrecioMoneda = null,
        public ?string $clavePromocion = null,
        public ?string $promocionDescripcion = null,
        public ?string $promocionExpiracion = null,
        public ?int $disponiblesEnPromocion = null,
        public ?string $ofertaPrecio = null,
        public ?string $precioRegular = null,
        public bool $esOferta = false
    )
    {
        
    }
}
