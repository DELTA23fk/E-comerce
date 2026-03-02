<?php

namespace App\Factories;

use App\Data\Producto\PromocionData;
use App\Models\TipoCambioMoneda;
use InvalidArgumentException;

class PromocionFactory
{
    /**
     * Punto de entrada unificado.
     * Siempre devuelve PromocionData[] sin importar el formato de origen.
     */
    public static function make(string $proveedor, mixed $data): array
    {
        return match($proveedor) {
            'cva' => self::fromCVA($data),
            'n1'  => self::fromN1($data),
            default => throw new InvalidArgumentException("Proveedor no soportado: {$proveedor}"),
        };
    }

    /**
     * CVA puede llegar como objeto único o como array de objetos.
     * ArticuloData::prepareForPipeline ya lo normaliza a array,
     * pero aquí lo manejamos defensivamente de todas formas.
     *
     * Entrada posible:
     *   - objeto único: { "total_descuento": 10, ... }
     *   - array:        [{ "total_descuento": 10, ... }]
     *
     * Salida: PromocionData[]
     */
    public static function fromCVA(mixed $data): array
    {
        if (empty($data)) return [];

        $items = self::normalizarALista($data);

        return collect($items)
            ->map(fn($item) => self::mapearCVA((array) $item))
            ->filter(fn(PromocionData $p) => $p->esOferta)
            ->values()
            ->all();
    }

    /**
     * Ingram llega como array de discounts, cada uno con
     * specialPricing[] y quantityDiscounts[].
     * Aplanamos todos los specialPricing en PromocionData individuales.
     *
     * Entrada:
     *   "discounts": [
     *     {
     *       "specialPricing":    [ {...}, {...} ],
     *       "quantityDiscounts": [ {...} ]
     *     }
     *   ]
     *
     * Salida: PromocionData[]
     */
    public static function fromN1(array $discounts): array
    {
        if (empty($discounts)) return [];

        $resultado = [];

        foreach ($discounts as $discount) {
            $specialPricings  = $discount['specialPricing']   ?? [];
            $quantityDiscount = collect($discount['quantityDiscounts'] ?? [])
                ->first(fn($qd) => !empty($qd['amount']));

            foreach ($specialPricings as $sp) {
                if (empty($sp['governmentDiscountedCustomerPrice']) && empty($sp['specialPricingDiscount'])) {
                    continue;
                }

                $promo = self::mapearN1($sp, $quantityDiscount);

                if ($promo->esOferta) {
                    $resultado[] = $promo;
                }
            }
        }

        return $resultado;
    }

    // =========================================================================
    // MAPEADORES INTERNOS
    // =========================================================================

    private static function mapearCVA(array $promo): PromocionData
    {
        $monedaOriginal = self::normalizeCurrency($promo['moneda_descuento'] ?? null) ?? 'MXN';
        $precioOriginal = isset($promo['precio_descuento']) ? (float) $promo['precio_descuento'] : null;

        [$tipoCambio, $precioMxn] = self::convertirAMxnSiAplica($monedaOriginal, $precioOriginal);

        $expiracionRaw   = $promo['promocion_vencimiento'] ?? null;
        $expiracionFecha = self::normalizeDate($expiracionRaw);
        $expiracionTexto = ($expiracionFecha === null && $expiracionRaw !== null)
            ? trim($expiracionRaw)
            : null;

        return new PromocionData(
            totalDescuento:        isset($promo['total_descuento']) ? (string) $promo['total_descuento'] : null,
            monedaDescuento:       self::normalizeCurrency($promo['moneda_descuento'] ?? null),
            monedaPrecioOriginal:  $monedaOriginal,
            precioConDescuento:    $precioOriginal,
            precioConDescuentoMxn: $precioMxn,
            tipoCambioUsado:       $tipoCambio,
            clavePromocion:        isset($promo['clave_promocion']) ? (string) $promo['clave_promocion'] : null,
            tipoDescuento:         null,
            descripcionPromocion:  $promo['descripcion_promocion'] ?? null,
            fechaInicio:           null,
            expiracionFecha:       $expiracionFecha,
            expiracionTexto:       $expiracionTexto,
            cantidadMinima:        null,
            disponibleEnPromocion: isset($promo['disponible_en_promocion'])
                                       ? (int) $promo['disponible_en_promocion']
                                       : null,
            precioRegular:         null,
            esOferta:              $precioOriginal !== null,
        );
    }

    private static function mapearN1(array $sp, ?array $quantityDiscount): PromocionData
    {
        $monedaOriginal = self::normalizeCurrency($quantityDiscount['currencyCode'] ?? null) ?? 'USD';
        $precioOriginal = isset($sp['governmentDiscountedCustomerPrice'])
            ? (float) $sp['governmentDiscountedCustomerPrice']
            : null;

        [$tipoCambio, $precioMxn] = self::convertirAMxnSiAplica($monedaOriginal, $precioOriginal);

        $expiracionRaw   = $sp['specialPricingExpirationDate'] ?? null;
        $expiracionFecha = self::normalizeDate($expiracionRaw);
        $expiracionTexto = ($expiracionFecha === null && $expiracionRaw !== null)
            ? trim($expiracionRaw)
            : null;

        return new PromocionData(
            totalDescuento:        isset($sp['specialPricingDiscount']) ? (string) $sp['specialPricingDiscount'] : null,
            monedaDescuento:       $monedaOriginal,
            monedaPrecioOriginal:  $monedaOriginal,
            precioConDescuento:    $precioOriginal,
            precioConDescuentoMxn: $precioMxn,
            tipoCambioUsado:       $tipoCambio,
            clavePromocion:        $sp['specialBidNumber'] ?? null,
            tipoDescuento:         $sp['governmentDiscountType'] ?? $sp['discountType'] ?? null,
            descripcionPromocion:  $sp['discountType'] ?? null,
            fechaInicio:           self::normalizeDate($sp['specialPricingEffectiveDate'] ?? null),
            expiracionFecha:       $expiracionFecha,
            expiracionTexto:       $expiracionTexto,
            cantidadMinima:        isset($sp['specialPricingMinQuantity'])
                                       ? (int) $sp['specialPricingMinQuantity']
                                       : null,
            disponibleEnPromocion: isset($sp['specialPricingAvailableQuantity'])
                                       ? (int) $sp['specialPricingAvailableQuantity']
                                       : null,
            precioRegular:         $quantityDiscount ? (float) $quantityDiscount['amount'] : null,
            esOferta:              $precioOriginal !== null || isset($sp['specialPricingDiscount']),
        );
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Convierte objeto o array asociativo a lista de items.
     * Cubre: objeto único, array de objetos, array asociativo.
     */
    private static function normalizarALista(mixed $data): array
    {
        if (empty($data)) return [];

        $array = is_object($data) ? (array) $data : $data;

        // Array asociativo = objeto único → envolver en lista
        if (!array_is_list($array)) {
            return [$array];
        }

        return $array;
    }

    /**
     * Convierte a MXN si la moneda no es MXN.
     * Devuelve [$tipoCambio|null, $precioMxn|null]
     */
    private static function convertirAMxnSiAplica(string $moneda, ?float $precio): array
    {
        if ($moneda === 'MXN' || $precio === null) {
            return [null, null];
        }

        $tipoCambio = (string) TipoCambioMoneda::actual();
        $precioMxn  = (float) bcmul((string) $precio, $tipoCambio, 4);

        return [$tipoCambio, $precioMxn];
    }

    private static function normalizeCurrency(?string $currency): ?string
    {
        if ($currency === null) return null;

        return match(strtolower(trim($currency))) {
            'pesos', 'peso', 'mxn'          => 'MXN',
            'dolares', 'dolar', 'usd', 'us' => 'USD',
            default                          => strtoupper(trim($currency)),
        };
    }

    private static function normalizeDate(?string $date): ?string
    {
        if ($date === null || trim($date) === '') return null;

        foreach (['d/m/Y', 'Y-m-d', 'd-m-Y', 'm/d/Y'] as $format) {
            $parsed = \DateTime::createFromFormat($format, trim($date));
            if ($parsed !== false) {
                return $parsed->format('Y-m-d');
            }
        }

        return null;
    }
}
