<?php

namespace App\Factories;

use App\Data\Producto\ProductoData;
use InvalidArgumentException;

class ProductoFactory
{
     /**
     * Centraliza la creación para que el controlador no sepa de lógica
     */
    public static function make(string $proveedor, array $data): ProductoData
    {
        return match($proveedor) {
            'cva'  => self::fromCVA($data),
            'exel' => self::fromExel($data),

            default => throw new InvalidArgumentException("Proveedor no soportado: {$proveedor}"),
        };
    }
    
    public static function fromExel(array $item): ProductoData
    {
        return new ProductoData(
            nombre: self::sanitizeString($item['nombre']),
            descripcion: self::sanitizeString($item['descripcion_extendida'] ?? $item['nombre']),
            descripcionTecnica: self::sanitizeString($item['ficha_tecnica'] ?? 'Sin descripcion tecnica'),
            codigoFabricante: self::sanitizeString($item['sku'] ?? null),
            codigoBarras: self::sanitizeString($item['codigo_barras'] ?? null),
            upc: self::sanitizeString($item['referencia'] ?? null),
            categoriaNombre: self::sanitizeString($item['categoria_nombre'] ?? 'General'),
            subcategoriaNombre: self::sanitizeString($item['subcategoria_nombre'] ?? 'General'),
            familiaNombre: self::sanitizeString($item['familia_nombre'] ?? 'General'),
            marcaNombre: self::sanitizeString($item['marca_nombre'] ?? 'General'),
            grupoNombre: null,
            proveedorProductoId: (string) ($item['id'] ?? ''),
            proveedorProductoCodigo: self::sanitizeString($item['sku'] ?? ''),
            moneda: strtoupper($item['moneda'] ?? 'MXN'),
            stock: self::toInt($item['stock'] ?? 0),
            stockCD: null,
            enOferta: self::toBool($item['oferta'] ?? false),
            garantia: self::sanitizeString($item['garantia'] ?? null),
            precioActual: self::toFloat($item['precio'] ?? 0),
            precioAnterior: null,
            imagenes: self::sanitizeImages($item['imagenes'] ?? []),
            descuentoTotal: null,
            descuentoMoneda: null,
            descuentoPrecio: null,
            descuentoPrecioMoneda: null,
            clavePromocion: null,
            promocionDescripcion: null,
            promocionExpiracion: null,
            disponiblesEnPromocion: null,
            ofertaPrecio: self::toBool($item['oferta'] ?? false) ? self::toFloat($item['precio_oferta'] ?? null) : null,
            precioRegular: self::toFloat($item['precio_sin_oferta'] ?? null),
            esOferta: self::toBool($item['oferta'] ?? false)
        );
    }

    public static function fromCVA(array $item): ProductoData
    {
        // Procesar imágenes
        $images = [];
        if (!empty($item['imagen'])) {
            $images[] = $item['imagen'];
        }
        if (isset($item['imagenes']) && is_array($item['imagenes'])) {
            $images = array_merge($images, $item['imagenes']);
        }
        $images = self::sanitizeImages($images);

        // Verificar si hay promociones
        $hasPromotion = isset($item['promociones']) && is_array($item['promociones']);
        $promos = $hasPromotion ? $item['promociones'] : [];

        return new ProductoData(
            nombre: self::sanitizeString($item['descripcion']),
            descripcion: self::sanitizeString($item['ficha_comercial'] ?? 'Sin descripción'),
            descripcionTecnica: self::sanitizeString($item['ficha_tecnica'] ?? 'Sin descripcion tecnica'),
            codigoFabricante: self::sanitizeString($item['codigo_fabricante'] ?? null),
            codigoBarras: null,
            upc: self::sanitizeString($item['upc'] ?? null),
            categoriaNombre: self::sanitizeString($item['principal'] ?? 'General'),
            subcategoriaNombre: null,
            familiaNombre: null,
            grupoNombre: self::sanitizeString($item['grupo'] ?? 'General'),
            marcaNombre: self::sanitizeString($item['marca'] ?? 'General'),
            proveedorProductoId: (string) ($item['id'] ?? ''),
            proveedorProductoCodigo: self::sanitizeString($item['clave'] ?? ''),
            moneda: self::normalizeCurrency($item['moneda'] ?? 'Pesos'),
            stock: self::toInt($item['disponible'] ?? 0),
            stockCD: self::toInt($item['disponibleCD'] ?? 0),
            enOferta: $hasPromotion,
            garantia: self::sanitizeString($item['garantia'] ?? null),
            precioActual: self::sanitizeString($item['precio'] ?? '00.00'),
            precioAnterior: null,
            imagenes: $images,
            
            // Datos de promociones con conversión segura
            descuentoTotal: $hasPromotion ? self::sanitizeString($promos['total_descuento'] ?? null) : null,
            descuentoMoneda: $hasPromotion ? self::normalizeCurrency($promos['moneda_descuento'] ?? null) : null,
            descuentoPrecio: $hasPromotion ? self::sanitizeString($promos['precio_descuento'] ?? null) : null,
            descuentoPrecioMoneda: $hasPromotion ? self::normalizeCurrency($promos['moneda_precio_descuento'] ?? null) : null,
            clavePromocion: $hasPromotion && isset($promos['clave_promocion']) ? (string) $promos['clave_promocion'] : null,
            promocionDescripcion: $hasPromotion ? self::sanitizeString($promos['descripcion_promocion'] ?? null) : null,
            promocionExpiracion: $hasPromotion ? $promos['promocion_vencimiento'] : null ,
            disponiblesEnPromocion: $hasPromotion ? self::toInt($promos['disponible_en_promocion'] ?? null) : null,
            ofertaPrecio: null,
            precioRegular: null,
            esOferta: $hasPromotion
        );
    }

    /**
     * Convierte valores a float de forma segura
     */
    protected static function toFloat($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        
        if (is_numeric($value)) {
            return (float) $value;
        }
        
        // Limpiar strings con formato de moneda
        $cleaned = preg_replace('/[^0-9.]/', '', (string) $value);
        return is_numeric($cleaned) ? (float) $cleaned : null;
    }

    /**
     * Convierte valores a int de forma segura
     */
    protected static function toInt($value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        
        if (is_numeric($value)) {
            return (int) $value;
        }
        
        $cleaned = preg_replace('/[^0-9]/', '', (string) $value);
        return is_numeric($cleaned) ? (int) $cleaned : 0;
    }

    /**
     * Convierte valores a bool de forma segura
     */
    protected static function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        
        if (is_numeric($value)) {
            return (int) $value === 1;
        }
        
        if (is_string($value)) {
            return in_array(strtolower($value), ['true', '1', 'yes', 'si', 'on'], true);
        }
        
        return false;
    }

    /**
     * Sanitiza strings removiendo espacios extras y caracteres raros
     */
    protected static function sanitizeString(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        
        // Remover espacios extras y caracteres de control
        $cleaned = preg_replace('/\s+/', ' ', trim($value));
        $cleaned = preg_replace('/[\x00-\x1F\x7F]/u', '', $cleaned);
        
        return $cleaned ?: null;
    }

    /**
     * Sanitiza array de imágenes
     */
    protected static function sanitizeImages(array $images): array
    {
        return array_values(array_filter(array_map(function ($url) {
            $clean = self::sanitizeString($url);
            // Validar que sea una URL válida
            return filter_var($clean, FILTER_VALIDATE_URL) ? $clean : null;
        }, $images)));
    }

    /**
     * Normaliza códigos de moneda
     */
    protected static function normalizeCurrency(?string $currency): ?string
    {
        if ($currency === null) {
            return null;
        }
        
        $map = [
            'pesos' => 'MXN',
            'peso' => 'MXN',
            'dolares' => 'USD',
            'dolar' => 'USD',
            'dollars' => 'USD',
            'dollar' => 'USD',
        ];
        
        $lower = strtolower(trim($currency));
        return $map[$lower] ?? strtoupper($currency);
    }

    /**
     * Sanitiza fechas
     */
    protected static function sanitizeDate(?string $date): ?string
    {
        if ($date === null || trim($date) === '') {
            return null;
        }
        
        try {
            $parsed = new \DateTime($date);
            return $parsed->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return null;
        }
    }
}
