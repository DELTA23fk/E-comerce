<?php

namespace App\Factories;

use App\Data\Cva\ArticuloData;
use App\Data\Producto\ProductoData;
use App\Data\Producto\PromocionData;
use App\Models\Proveedor;
use App\Models\TipoCambioMoneda;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

class ProductoFactory
{
    public static function make(string $proveedor, mixed $data): ProductoData
    {
        return match($proveedor) {
            'cva'  => self::fromCVA($data),
            default => throw new InvalidArgumentException("Proveedor no soportado: {$proveedor}"),
        };
    }

    public static function fromCVA(ArticuloData $articulo): ProductoData
    {
        $images = self::sanitizeImages(array_merge(
            !empty($articulo->imagen)   ? [$articulo->imagen]   : [],
            !empty($articulo->imagenes) ? $articulo->imagenes   : [],
        ));

        $monedaOriginal = self::normalizeCurrency($articulo->moneda ?? 'MXN');
        $utilidad       = self::porcentajeUtilidadDeProveedorCache('cva');
        $tipoCambio     = self::getTipoCambioMxn();

        $precioRaw   = self::sanitizePrecio($articulo->precio);
        $precioEnMxn = $precioRaw !== null
            ? self::convertirAMxn($precioRaw, $monedaOriginal, $tipoCambio)
            : null;
        $precioBase  = $precioEnMxn !== null
            ? self::extraerPrecioBase($precioEnMxn, $utilidad)
            : null;

        $promociones = PromocionFactory::fromCVA(
            $articulo->promociones?->toCollection()->all() ?? []
        );

        // ── Almacenes ─────────────────────────────────────────────────────────
        // AlmacenStockFactory maneja ambos endpoints:
        //   - disponibilidad_sucursales[] (endpoint general, sync inicial)
        //   - disponible + disponibleCD   (endpoint ligero, actualizacion)
        // En ambos casos garantiza que GDL (1) y CEDIS GDL (46) siempre existen.
        $almacenes = AlmacenStockFactory::fromCVA($articulo);

        // Stock resumen calculado desde los almacenes para mantener consistencia
        // con el campo proveedor_productos.stock / stock_cd
        $stockTotal = array_sum(array_map(fn($a) => $a->cantidad, $almacenes));
        

        return new ProductoData(
            nombre:                     self::sanitizeString($articulo->descripcion) ?? 'Sin nombre',
            descripcion:                self::sanitizeString($articulo->fichaComercial),
            descripcionTecnica:         self::sanitizeString($articulo->fichaTecnica),
            codigoFabricante:           self::sanitizeString($articulo->codigoFabricante),
            codigoBarras:               null,
            upc:                        self::sanitizeString($articulo->upc),
            categoriaNombre:            self::sanitizeString($articulo->principal ?? 'General'),
            subcategoriaNombre:         null,
            familiaNombre:              null,
            grupoNombre:                self::sanitizeString($articulo->grupo    ?? 'General'),
            marcaNombre:                self::sanitizeString($articulo->marca    ?? 'General'),
            proveedorProductoId:        (string) $articulo->id,
            proveedorProductoCodigo:    self::sanitizeString($articulo->clave),
            garantia:                   self::sanitizeString($articulo->garantia),
            stockTotal:                 $stockTotal,  // suma de todos los almacenes
            enOferta:                   !empty($promociones),
            monedaBaseProducto:         $monedaOriginal,
            precioBaseProducto:         $precioBase,
            precioRecomendadoProveedor: null,
            moneda:                     'MXN',
            precioActual:               $precioEnMxn,
            precioAnterior:             null,
            porcentajeUtilidadAplicado: $utilidad,
            tipoCambioUsadoMxn:         ($monedaOriginal !== 'MXN' && $precioRaw !== null)
                                            ? $tipoCambio
                                            : null,
            imagenes:                   $images,
            promociones:                $promociones,
            almacenes:                  $almacenes,
        );
    }

    // =========================================================================
    // PRECIOS
    // =========================================================================

    public static function porcentajeUtilidadDeProveedorCache(string $claveProveedor): ?int
    {
        return Cache::remember(
            "proveedor_utilidad_{$claveProveedor}",
            now()->addHours(6),
            function () use ($claveProveedor) {
                $porcentaje = Proveedor::where('codigo_proveedor', $claveProveedor)
                    ->pluck('porcentaje_utilidad')
                    ->first();
                return (int) $porcentaje ?? null;
            }
        );
    }

    public static function getTipoCambioMxn(): string
    {
        return (string) TipoCambioMoneda::actual();
    }

    public static function convertirAMxn(string $precio, string $moneda, string $tipoCambio): string
    {
        if ($moneda === 'MXN') return $precio;

        return bcmul($precio, $tipoCambio, 4);
    }

    public static function calcularPrecioVenta(string $precioMxn, ?int $porcentajeUtilidad): string
    {
        if ($porcentajeUtilidad === null || $porcentajeUtilidad === 0) return $precioMxn;

        $factor = bcadd('1', bcdiv((string) $porcentajeUtilidad, '100', 6), 6);
        return bcmul($precioMxn, $factor, 2);
    }

    public static function extraerPrecioBase(string $precioConUtilidad, ?int $porcentajeUtilidad): string
    {
        if ($porcentajeUtilidad === null || $porcentajeUtilidad === 0) return $precioConUtilidad;

        $factor = bcadd('1', bcdiv((string) $porcentajeUtilidad, '100', 6), 6);
        return bcdiv($precioConUtilidad, $factor, 4);
    }

    // =========================================================================
    // SANITIZACIÓN
    // =========================================================================

    protected static function toFloat($value): ?float
    {
        if ($value === null || $value === '') return null;
        if (is_numeric($value)) return (float) $value;

        $cleaned = preg_replace('/[^0-9.]/', '', (string) $value);
        return is_numeric($cleaned) ? (float) $cleaned : null;
    }

    protected static function toInt($value): int
    {
        if ($value === null || $value === '') return 0;
        if (is_numeric($value)) return (int) $value;

        $cleaned = preg_replace('/[^0-9]/', '', (string) $value);
        return is_numeric($cleaned) ? (int) $cleaned : 0;
    }

    protected static function toBool($value): bool
    {
        if (is_bool($value))    return $value;
        if (is_numeric($value)) return (int) $value === 1;

        if (is_string($value)) {
            return in_array(strtolower($value), ['true', '1', 'yes', 'si', 'on'], true);
        }

        return false;
    }

    protected static function sanitizeString(?string $value): ?string
    {
        if ($value === null || trim($value) === '') return null;

        $cleaned = preg_replace('/\s+/', ' ', trim($value));
        $cleaned = preg_replace('/[\x00-\x1F\x7F]/u', '', $cleaned);

        return $cleaned ?: null;
    }

    protected static function sanitizeImages(array $images): array
    {
        return array_values(array_filter(array_map(function ($url) {
            $clean = self::sanitizeString($url);
            return filter_var($clean, FILTER_VALIDATE_URL) ? $clean : null;
        }, $images)));
    }

    protected static function normalizeCurrency(?string $currency): ?string
    {
        if ($currency === null) return null;

        $map = [
            'pesos'   => 'MXN', 'peso'    => 'MXN',
            'dolares' => 'USD', 'dolar'   => 'USD',
            'dollars' => 'USD', 'dollar'  => 'USD',
            'mxn'     => 'MXN', 'usd'     => 'USD',
        ];

        $lower = strtolower(trim($currency));
        return $map[$lower] ?? strtoupper($currency);
    }

    protected static function sanitizeDate(?string $date): ?string
    {
        if ($date === null || trim($date) === '') return null;

        try {
            return (new \DateTime($date))->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }

    protected static function sanitizePrecio(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') return null;

        $cleaned = preg_replace('/[^0-9.]/', '', (string) $value);

        if ($cleaned === '' || !is_numeric($cleaned) || (float) $cleaned <= 0) return null;

        return $cleaned;
    }
}