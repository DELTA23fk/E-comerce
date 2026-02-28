<?php

namespace App\Data\Producto;

readonly class ProductoData
{
    // CLASE ESTANDAR PARA LA CREACION DE PRODUTOS Y SUS RELACIONES
    public function __construct(
         // Datos básicos de 'products'
        public string $nombre,
        public ?string $descripcion,
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
        public  int $stock,
        public  ?int $stockCD,
        public  bool $enOferta,
        public  ?string $garantia,
        
        // Datos para 'provider_product_prices'
        // NOTA: uso de string para precisión de precios, se hace uso de bcmath
        public  string $moneda,//moneda de venta, no es la moneda del producto base
        public  ?string $precioActual = null, 
        public  ?string $precioAnterior = null,
        public  ?string $precioBaseProducto = null,
        public  ?string $monedaBaseProducto = null,
        public  ?string $precioRecomendadoProveedor = null,
        public  ?int $porcentajeUtilidadAplicado = null,
        public ?string $tipoCambioUsadoMxn = null,


        // Imágenes
        public  array $imagenes = [],

        /** @var PromocionData[] */
        public array $promociones = [],

    )
    {
        
    }
}
