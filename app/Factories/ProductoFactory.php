<?php

namespace App\Factories;

use App\Data\Cva\ArticuloData;
use App\Data\Producto\ProductoData;
use InvalidArgumentException;

class ProductoFactory
{
    /**
     * Centraliza la creación desde diferentes proveedores
     * 
     * @param string $proveedor Nombre del proveedor
     * @param mixed $data Objeto tipado del proveedor (ArticuloData, ExelData, etc)
     * @return ProductoData DTO unificado para el sistema
     */
    public static function make(string $proveedor, mixed $data): ProductoData
    {
        return match($proveedor) {
            'cva'  => self::fromCVA($data),
            'exel' => self::fromExel($data),
            default => throw new InvalidArgumentException("Proveedor no soportado: {$proveedor}"),
        };
    }
    
    /**
     * Convierte ArticuloData (CVA) a ProductoData unificado
     */
    public static function fromCVA(ArticuloData $articulo): ProductoData
    {
        // Procesar imágenes
        $images = [];
        if (!empty($articulo->imagen)) {
            $images[] = $articulo->imagen;
        }
        if (!empty($articulo->imagenes)) {
            $images = array_merge($images, $articulo->imagenes);
        }
        $images = self::sanitizeImages($images);

        // Verificar si hay promociones (DataCollection o null)
        $hasPromotion = $articulo->promociones !== null && $articulo->promociones->count() > 0;
        
        // Obtener primera promoción si existe
        $promo = $hasPromotion ? $articulo->promociones->first() : null;

        return new ProductoData(
            nombre: self::sanitizeString($articulo->descripcion),
            descripcion: self::sanitizeString($articulo->descripcion ?? 'Sin descripción'),
            descripcionTecnica: 'Sin descripcion tecnica', // CVA no tiene este campo
            codigoFabricante: self::sanitizeString($articulo->codigoFabricante),
            codigoBarras: null, // CVA no tiene código de barras
            upc: self::sanitizeString($articulo->upc),
            categoriaNombre: self::sanitizeString($articulo->principal ?? 'General'),
            subcategoriaNombre: null, // CVA no tiene subcategoría
            familiaNombre: null, // CVA no tiene familia
            grupoNombre: self::sanitizeString($articulo->grupo ?? 'General'),
            marcaNombre: self::sanitizeString($articulo->marca ?? 'General'),
            proveedorProductoId: (string) $articulo->id,
            proveedorProductoCodigo: self::sanitizeString($articulo->clave),
            moneda: self::normalizeCurrency($articulo->moneda ?? null),
            stock: $articulo->disponible,
            stockCD: $articulo->disponibleCD,
            enOferta: $hasPromotion,
            garantia: self::sanitizeString($articulo->garantia),
            precioActual: $articulo->precio,
            precioAnterior: null,
            imagenes: $images,
            
            // Datos de promociones desde PromocionCvaData
            descuentoTotal: $promo?->precio_descuento,
            descuentoMoneda: $promo ? self::normalizeCurrency($promo->moneda_descuento) : null,
            descuentoPrecio: $promo?->precio_descuento,
            descuentoPrecioMoneda: $promo ? self::normalizeCurrency($promo->moneda_descuento) : null,
            clavePromocion: $promo?->clave_promocion,
            promocionDescripcion: $promo?->descripcion_promocion,
            promocionExpiracion: $promo?->promocion_vencimiento,
            disponiblesEnPromocion: $promo?->disponible_en_promocion,
            ofertaPrecio: null,
            precioRegular: null,
            esOferta: $hasPromotion
        );
    }

    /**
     * Convierte datos de Exel a ProductoData unificado
     * 
     * @param array $item Mantener como array hasta que tengas ExelData tipado
     */
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
            precioActual: (string) self::toFloat($item['precio'] ?? 0),
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
            ofertaPrecio: self::toBool($item['oferta'] ?? false) 
                ? (string) self::toFloat($item['precio_oferta'] ?? null) 
                : null,
            precioRegular: (string) self::toFloat($item['precio_sin_oferta'] ?? null),
            esOferta: self::toBool($item['oferta'] ?? false)
        );
    }

    // =========================================================================
    // MÉTODOS DE SANITIZACIÓN Y CONVERSIÓN
    // =========================================================================

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