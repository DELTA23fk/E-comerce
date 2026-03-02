<?php

namespace App\Factories;

use App\Data\Producto\AlmacenStockData;
use InvalidArgumentException;

class AlmacenStockFactory
{
     // =========================================================================
    // CATÁLOGO OFICIAL CVA
    // =========================================================================

    private const SUCURSALES_CVA = [
        '1'  => ['nombre' => 'GUADALAJARA',            'cp' => '44900', 'es_cd' => false],
        '3'  => ['nombre' => 'MORELIA',                'cp' => '58000', 'es_cd' => false],
        '4'  => ['nombre' => 'LEON',                   'cp' => '37200', 'es_cd' => false],
        '5'  => ['nombre' => 'CULIACAN',               'cp' => '80200', 'es_cd' => false],
        '6'  => ['nombre' => 'QUERETARO',              'cp' => '76148', 'es_cd' => false],
        '7'  => ['nombre' => 'TORREON',                'cp' => '27000', 'es_cd' => false],
        '8'  => ['nombre' => 'TEPIC',                  'cp' => '63159', 'es_cd' => false],
        '9'  => ['nombre' => 'MONTERREY',              'cp' => '64640', 'es_cd' => false],
        '10' => ['nombre' => 'PUEBLA',                 'cp' => '72060', 'es_cd' => false],
        '11' => ['nombre' => 'VERACRUZ',               'cp' => '91700', 'es_cd' => false],
        '12' => ['nombre' => 'VILLAHERMOSA',           'cp' => '86190', 'es_cd' => false],
        '13' => ['nombre' => 'TUXTLA',                 'cp' => '29066', 'es_cd' => false],
        '14' => ['nombre' => 'HERMOSILLO',             'cp' => '83188', 'es_cd' => false],
        '18' => ['nombre' => 'MERIDA',                 'cp' => '97000', 'es_cd' => false],
        '19' => ['nombre' => 'CANCUN',                 'cp' => '77520', 'es_cd' => false],
        '23' => ['nombre' => 'AGUASCALIENTES',         'cp' => '20040', 'es_cd' => false],
        '24' => ['nombre' => 'CDMX',                   'cp' => '03230', 'es_cd' => false],
        '26' => ['nombre' => 'SAN LUIS POTOSI',        'cp' => '78230', 'es_cd' => false],
        '27' => ['nombre' => 'CHIHUAHUA',              'cp' => '31105', 'es_cd' => false],
        '28' => ['nombre' => 'DURANGO',                'cp' => '34000', 'es_cd' => false],
        '29' => ['nombre' => 'TOLUCA',                 'cp' => '50090', 'es_cd' => false],
        '31' => ['nombre' => 'OAXACA',                 'cp' => '68000', 'es_cd' => false],
        '32' => ['nombre' => 'LA PAZ',                 'cp' => '23000', 'es_cd' => false],
        '33' => ['nombre' => 'TIJUANA',                'cp' => '22425', 'es_cd' => false],
        '35' => ['nombre' => 'COLIMA',                 'cp' => '28000', 'es_cd' => false],
        '36' => ['nombre' => 'ZACATECAS',              'cp' => '98000', 'es_cd' => false],
        '38' => ['nombre' => 'CAMPECHE',               'cp' => '24050', 'es_cd' => false],
        '39' => ['nombre' => 'TAMPICO',                'cp' => '89440', 'es_cd' => false],
        '40' => ['nombre' => 'PACHUCA',                'cp' => '42039', 'es_cd' => false],
        '43' => ['nombre' => 'ACAPULCO',               'cp' => '39355', 'es_cd' => false],
        '46' => ['nombre' => 'CEDIS GUADALAJARA',      'cp' => '45640', 'es_cd' => true ],
        '47' => ['nombre' => 'CUERNAVACA',             'cp' => '62170', 'es_cd' => false],
        '51' => ['nombre' => 'CEDIS CDMX CENTRO SUR',  'cp' => '54913', 'es_cd' => true ],
        '54' => ['nombre' => 'CEDIS MONTERREY',        'cp' => '66626', 'es_cd' => true ],
    ];

    // Claves que CVA nunca incluye en disponibilidad_sucursales —
    // sus cantidades siempre vienen en los campos raíz disponible / disponibleCD
    private const CLAVE_SUCURSAL_GDL = '1';
    private const CLAVE_CEDIS_GDL    = '46';

    // =========================================================================
    // ENTRADA PÚBLICA
    // =========================================================================

    /**
     * Punto de entrada unificado. Siempre devuelve AlmacenStockData[].
     * Extender el match cuando llegue un nuevo proveedor.
     */
    public static function make(string $proveedor, mixed $data): array
    {
        return match($proveedor) {
            'cva'   => self::fromCVA($data),
            'n1'    => self::fromN1($data),
            // Proveedores con formato genérico de warehouses:
            // 'syscom' => self::fromWarehouseArray($data, [...mapa...]),
            // 'exel'   => self::fromWarehouseArray($data, [...mapa...]),
            default => throw new InvalidArgumentException(
                "Proveedor '{$proveedor}' no tiene implementación de almacenes. " .
                "Agregar case en AlmacenStockFactory::make() o usar fromWarehouseArray()."
            ),
        };
    }

    // =========================================================================
    // CVA
    // =========================================================================

    /**
     * Punto de entrada CVA.
     *
     * ENDPOINT GENERAL (sync inicial):
     *   Trae disponibilidad_sucursales[] con clave, nombre, cp y disponible.
     *   Se mapea contra SUCURSALES_CVA. GDL + CEDIS GDL se garantizan al final.
     *
     * ENDPOINT LIGERO (actualizacion):
     *   Solo trae disponible + disponibleCD en la raíz.
     *   Construye los dos almacenes GDL conocidos directamente.
     */
    public static function fromCVA(object $articulo): array
    {
        if (!empty($articulo->disponibilidadSucursales)) {
            return self::fromCVASucursales(
                (array) $articulo->disponibilidadSucursales,
                $articulo
            );
        }

        return self::fromCVACamposResumidos(
            (int) ($articulo->disponible   ?? 0),
            (int) ($articulo->disponibleCD ?? 0),
        );
    }

    /**
     * Parsea disponibilidad_sucursales[].
     *
     * Estrategia de resolución de metadatos por sucursal:
     *   1. Buscar por clave numérica en SUCURSALES_CVA
     *   2. Fallback: normalizar nombre (quitar prefijos "VENTAS ", "CENTRO DE DISTRIBUCION ", etc.)
     *      y buscar en índice inverso nombre → clave
     *   3. Fallback final: construir desde los datos del array tal cual
     *
     * Al final siempre garantiza GDL (1) y CEDIS GDL (46) desde los campos raíz.
     */
    private static function fromCVASucursales(array $sucursales, object $articulo): array
    {
        $almacenes         = [];
        $clavesEncontradas = [];

        // Índice inverso nombre canónico → clave para fallback por nombre
        $indicePorNombre = [];
        foreach (self::SUCURSALES_CVA as $clave => $data) {
            $indicePorNombre[strtoupper($data['nombre'])] = $clave;
        }

        foreach ($sucursales as $sucursal) {
            $sucursal = (array) $sucursal;
            $nombre   = strtoupper(trim($sucursal['nombre'] ?? ''));

            // Fila resumen que CVA añade al final del array
            if ($nombre === 'TOTAL') continue;

            $cantidad = (int) ($sucursal['disponible'] < 0 ? 0 : $sucursal['disponible']);
            $claveRaw = (string) ($sucursal['clave']    ?? '');

            // ── 1. Resolver por clave numérica ────────────────────────────────
            $meta = self::SUCURSALES_CVA[$claveRaw] ?? null;

            // ── 2. Fallback: resolver por nombre normalizado ───────────────────
            // CVA puede mandar "VENTAS GUADALAJARA", "CENTRO DE DISTRIBUCION MEXICO",
            // "RETAIL CDMX", etc. en lugar del nombre canónico
            if ($meta === null) {
                $nombreNormalizado = preg_replace(
                    '/^(VENTAS|CENTRO DE DISTRIBUCION|RETAIL|CD)\s+/i',
                    '',
                    $nombre
                );

                $claveResuelta = $indicePorNombre[$nombreNormalizado]
                    ?? $indicePorNombre[$nombre]
                    ?? null;

                if ($claveResuelta) {
                    $meta     = self::SUCURSALES_CVA[$claveResuelta];
                    $claveRaw = $claveResuelta;
                }
            }

            // ── 3. Fallback final: construir desde el array ───────────────────
            if ($meta === null) {
                $meta = [
                    'nombre' => $nombre,
                    'cp'     => (string) ($sucursal['codigo_postal'] ?? ''),
                    'es_cd'  => str_contains($nombre, 'CEDIS')
                             || str_contains($nombre, 'DISTRIBUC'),
                ];
            }

            $clavesEncontradas[] = $claveRaw;

            $almacenes[] = new AlmacenStockData(
                almacenIdExterno: $claveRaw ?: null,
                almacenNombre:    $meta['nombre'],
                codigoPostal:     $meta['cp']   ?: null,
                cantidad:         $cantidad,
                esPrincipal:      $claveRaw === self::CLAVE_SUCURSAL_GDL,
                esCd:             $meta['es_cd'],
            );
        }

        // ── Garantizar GUADALAJARA (1) y CEDIS GUADALAJARA (46) ──────────────
        // Nunca aparecen en disponibilidad_sucursales — CVA siempre los
        // expone en los campos raíz disponible / disponibleCD
        if (!in_array(self::CLAVE_SUCURSAL_GDL, $clavesEncontradas, true)) {
            $meta        = self::SUCURSALES_CVA[self::CLAVE_SUCURSAL_GDL];
            $almacenes[] = new AlmacenStockData(
                almacenIdExterno: self::CLAVE_SUCURSAL_GDL,
                almacenNombre:    $meta['nombre'],
                codigoPostal:     $meta['cp'],
                cantidad:         (int) ($articulo->disponible ?? 0),
                esPrincipal:      true,
                esCd:             false,
            );
        }

        if (!in_array(self::CLAVE_CEDIS_GDL, $clavesEncontradas, true)) {
            $meta        = self::SUCURSALES_CVA[self::CLAVE_CEDIS_GDL];
            $almacenes[] = new AlmacenStockData(
                almacenIdExterno: self::CLAVE_CEDIS_GDL,
                almacenNombre:    $meta['nombre'],
                codigoPostal:     $meta['cp'],
                cantidad:         (int) ($articulo->disponibleCD ?? 0),
                esPrincipal:      false,
                esCd:             true,
            );
        }

        return $almacenes;
    }

    /**
     * Endpoint ligero: construye solo los dos almacenes GDL conocidos.
     */
    private static function fromCVACamposResumidos(int $disponible, int $disponibleCD): array
    {
        $sucursal = self::SUCURSALES_CVA[self::CLAVE_SUCURSAL_GDL];
        $cedis    = self::SUCURSALES_CVA[self::CLAVE_CEDIS_GDL];

        return [
            new AlmacenStockData(
                almacenIdExterno: self::CLAVE_SUCURSAL_GDL,
                almacenNombre:    $sucursal['nombre'],
                codigoPostal:     $sucursal['cp'],
                cantidad:         $disponible,
                esPrincipal:      true,
                esCd:             false,
            ),
            new AlmacenStockData(
                almacenIdExterno: self::CLAVE_CEDIS_GDL,
                almacenNombre:    $cedis['nombre'],
                codigoPostal:     $cedis['cp'],
                cantidad:         $disponibleCD,
                esPrincipal:      false,
                esCd:             true,
            ),
        ];
    }

    // =========================================================================
    // INGRAM MICRO N1
    // =========================================================================

    /**
     * Acepta el objeto "availability" completo o directamente el array
     * "availabilityByWarehouse".
     *
     * Formato esperado:
     * {
     *   "available": false,
     *   "totalAvailability": 0,
     *   "availabilityByWarehouse": [
     *     {
     *       "warehouseId": 20,
     *       "location": "Fort Worth, TX",
     *       "quantityAvailable": 0,
     *       "quantityBackordered": 0,
     *       "backOrderInfo": [
     *         { "quantity": 1437, "etaDate": "2025-01-01" },
     *         { "quantity": 8163, "etaDate": "2026-01-01" }
     *       ]
     *     }
     *   ]
     * }
     */
    public static function fromN1(array|object $availability): array
    {
        // Resolver el array de warehouses sin importar qué nivel se pasó
        $warehouses = match(true) {
            is_array($availability) && isset($availability[0])         => $availability,
            is_array($availability) && isset($availability['availabilityByWarehouse'])
                                                                        => $availability['availabilityByWarehouse'],
            is_object($availability) && isset($availability->availabilityByWarehouse)
                                                                        => (array) $availability->availabilityByWarehouse,
            default                                                     => [],
        };

        $almacenes = [];

        foreach ($warehouses as $wh) {
            $wh = (array) $wh;

            // Ingram a veces manda {} vacíos en el array
            if (empty($wh) || !isset($wh['warehouseId'])) continue;

            // Backorder: priorizar backOrderInfo detallado sobre quantityBackordered
            $backorder    = null;
            $etaBackorder = null;

            if (!empty($wh['backOrderInfo'])) {
                // Tomar el lote más próximo (primer elemento del array)
                $primero      = (array) $wh['backOrderInfo'][0];
                $backorder    = (int) ($primero['quantity'] ?? 0);
                $etaBackorder = self::normalizeDate($primero['etaDate'] ?? null);
            } elseif (isset($wh['quantityBackordered']) && $wh['quantityBackordered'] > 0) {
                // Fallback: campo raíz sin detalle de fechas
                $backorder = (int) $wh['quantityBackordered'];
            }

            $almacenes[] = new AlmacenStockData(
                almacenIdExterno: (string) $wh['warehouseId'],
                almacenNombre:    strtoupper($wh['location'] ?? 'WAREHOUSE ' . $wh['warehouseId']),
                codigoPostal:     null, // Ingram no provee CP
                cantidad:         (int) ($wh['quantityAvailable'] ?? 0),
                esPrincipal:      false,
                esCd:             true, // todos son CDs para Ingram
                backorder:        $backorder,
                etaBackorder:     $etaBackorder,
            );
        }

        return $almacenes;
    }

    // =========================================================================
    // GENÉRICO — para proveedores futuros con formato similar
    // =========================================================================

    /**
     * Para proveedores que mandan un array plano de almacenes con campos
     * configurables mediante un mapa nombre_campo_nuestro → nombre_campo_api.
     *
     * Ejemplo Syscom hipotético:
     *   AlmacenStockFactory::fromWarehouseArray($data, [
     *       'id'       => 'warehouse_id',
     *       'nombre'   => 'warehouse_name',
     *       'cantidad' => 'stock',
     *   ]);
     */
    public static function fromWarehouseArray(
        array $warehouses,
        array $mapa = [
            'id'       => 'warehouseId',
            'nombre'   => 'location',
            'cantidad' => 'quantityAvailable',
        ]
    ): array {
        $almacenes = [];

        foreach ($warehouses as $wh) {
            $wh = (array) $wh;
            if (empty($wh)) continue;

            $id       = (string) ($wh[$mapa['id']]       ?? '');
            $nombre   = strtoupper((string) ($wh[$mapa['nombre']]   ?? 'ALMACEN ' . $id));
            $cantidad = (int)    ($wh[$mapa['cantidad']] ?? 0);

            if ($id === '') continue;

            $almacenes[] = new AlmacenStockData(
                almacenIdExterno: $id,
                almacenNombre:    $nombre,
                codigoPostal:     null,
                cantidad:         $cantidad,
                esPrincipal:      false,
                esCd:             true,
            );
        }

        return $almacenes;
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private static function normalizeDate(?string $date): ?string
    {
        if ($date === null || trim($date) === '') return null;

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y'] as $format) {
            $parsed = \DateTime::createFromFormat($format, trim($date));
            if ($parsed !== false) return $parsed->format('Y-m-d');
        }

        return null;
    }
}
