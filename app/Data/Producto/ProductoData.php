<?php

namespace App\Data\Producto;

use App\Data\Producto\AlmacenStockData;

readonly class ProductoData
{
    /**
     * @param PromocionData[]    $promociones
     * @param AlmacenStockData[] $almacenes
     */
    public function __construct(
        // ── Datos básicos — productos ─────────────────────────────────────────
        public string  $nombre,
        public ?string $descripcion,
        public ?string $descripcionTecnica,
        public ?string $codigoFabricante,
        public ?string $codigoBarras,
        public ?string $upc,

        // ── Relaciones (nombres para resolver IDs) ────────────────────────────
        public ?string $categoriaNombre,
        public ?string $subcategoriaNombre,
        public ?string $familiaNombre,
        public ?string $grupoNombre,
        public ?string $marcaNombre,

        // ── Datos para proveedor_productos ────────────────────────────────────
        public string  $proveedorProductoId,     // id que viene de la API
        public string  $proveedorProductoCodigo, // sku / clave
        public int     $stockTotal,                   // resumen total (suma de almacenes)
        public bool    $enOferta,
        public ?string $garantia,

        // ── Datos para proveedor_producto_precios ─────────────────────────────
        // Se usa string para precisión bcmath
        public string  $moneda,                         // moneda de venta final (siempre MXN)
        public ?string $precioActual               = null, // null si precio inválido
        public ?string $precioAnterior             = null,
        public ?string $precioBaseProducto         = null, // null si precio inválido
        public ?string $monedaBaseProducto         = null, // moneda original del proveedor
        public ?string $precioRecomendadoProveedor = null,
        public ?int    $porcentajeUtilidadAplicado = null,
        public ?string $tipoCambioUsadoMxn         = null, // null si precio ya venía en MXN

        // ── Imágenes ──────────────────────────────────────────────────────────
        public array $imagenes = [],

        // ── Promociones ───────────────────────────────────────────────────────
        public array $promociones = [],

        // ── Almacenes ─────────────────────────────────────────────────────────
        // Detalle de stock por almacén/sucursal.
        // Vacío para proveedores que no proveen estructura de warehouses (Exel).
        // CVA: todas las sucursales + garantizados GDL (1) y CEDIS GDL (46).
        // Ingram N1: availabilityByWarehouse[].
        public array $almacenes = [],
    ) {}
}