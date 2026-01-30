<?php

namespace App\Data\Cva;

use Spatie\LaravelData\Data;

class PromocionCvaData extends Data
{
    public function __construct(
        public string $precio_descuento,
        public string $moneda_descuento,
        public string $clave_promocion,
        public string $descripcion_promocion,
        public string $promocion_vencimiento,
        public int $disponible_en_promocion
    ) {}

    public static function prepareForPipeline(array $properties): array
    {
        // Protección: si no es array, devolver valores por defecto
        if (!is_array($properties) || empty($properties)) {
            return [
                'precio_descuento' => '0.00',
                'moneda_descuento' => 'MXN',
                'clave_promocion' => '',
                'descripcion_promocion' => '',
                'promocion_vencimiento' => '',
                'disponible_en_promocion' => 0,
            ];
        }

        return [
            'precio_descuento' => (string) ($properties['precio_descuento'] ?? $properties['precio'] ?? '0.00'),
            'moneda_descuento' => (string) ($properties['moneda_precio_descuento'] ?? $properties['moneda'] ?? 'MXN'),
            'clave_promocion' => (string) ($properties['clave_promocion'] ?? $properties['clave'] ?? ''),
            'descripcion_promocion' => (string) ($properties['descripcion_promocion'] ?? $properties['descripcion'] ?? ''),
            'promocion_vencimiento' => (string) ($properties['promocion_vencimiento'] ?? $properties['vencimiento'] ?? ''),
            'disponible_en_promocion' => (int) ($properties['disponible_en_promocion'] ?? $properties['disponible'] ?? 0),
        ];
    }
}