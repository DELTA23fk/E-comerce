<?php

namespace App\Data\Cva;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapInputName(SnakeCaseMapper::class)]
class ArticuloData extends Data
{
    public function __construct(
        public int $id,
        public string $clave,
        public ?string $upc,
        public string $codigoFabricante,
        public string $descripcion,
        public ?string $fichaTecnica,
        public ?string $fichaComercial,
        public string $principal,
        public string $grupo,
        public int $disponible,
        public string $marca,
        public string $garantia,
        public ?string $moneda,
        public string $precio,
        public ?string $imagen,
        public ?array $imagenes,
        public int $disponibleCD,

        #[DataCollectionOf(PromocionCvaData::class)]
        public ?DataCollection $promociones,
    ) {}

    public static function prepareForPipeline(array $properties): array
    {
        // Obtener el valor raw (puede ser null, string, array, objeto)
        $rawPromo = $properties['promociones'] ?? $properties['promocion'] ?? null;

        $promosFinales = null;

        // Solo procesar si es un array válido y no vacío
        if (is_array($rawPromo) && !empty($rawPromo)) {
            
            // Si es un objeto único (tiene claves de promoción), convertir a array
            if (isset($rawPromo['precio_descuento']) || 
                isset($rawPromo['precio']) || 
                isset($rawPromo['clave_promocion'])) {
                $promosFinales = [$rawPromo];
            } else {
                // Ya es un array de promociones
                $promosFinales = $rawPromo;
            }
        }
        // Si es string vacío "", null o [], quedaría null

        return array_merge($properties, [
            'promociones' => $promosFinales
        ]);
    }
}