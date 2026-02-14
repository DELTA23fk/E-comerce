<?php

namespace App\Data\Pedidos;

use App\Models\ProveedorProducto;

readonly class ProductoEnriquecidoData
{
    public function __construct(
        public int $proveedorProductoId,
        public string $codigoProveedor,
        public int $cantidad,
        public float $precioUnitario,
        public int $proveedorId,
        public int $productoId,
        public ?int $stock = null,
        public ?int $stockCd = null,
    ) {}

    /**
     * Crear desde ProveedorProducto y cantidad solicitada
     */
    public static function fromProveedorProducto(
        ProveedorProducto $proveedorProducto, 
        int $cantidad
    ): self {
        if (!$proveedorProducto->pricio) {
            throw new \InvalidArgumentException(
                "Producto '{$proveedorProducto->codigo_proveedor}' no tiene precio configurado"
            );
        }

        return new self(
            proveedorProductoId: $proveedorProducto->id,
            codigoProveedor: $proveedorProducto->codigo_proveedor,
            cantidad: $cantidad,
            precioUnitario: (float) $proveedorProducto->pricio->precio_actual,
            proveedorId: $proveedorProducto->proveedor_id,
            productoId: $proveedorProducto->producto_id,
            stock: $proveedorProducto->stock,
            stockCd: $proveedorProducto->stock_cd,
        );
    }

    /**
     * Calcular subtotal
     */
    public function getSubtotal(): float
    {
        return round($this->cantidad * $this->precioUnitario, 2);
    }

    /**
     * Convertir a array para enviar a proveedores
     */
    public function toArray(): array
    {
        return [
            'proveedor_producto_id' => $this->proveedorProductoId,
            'proveedor_id' => $this->proveedorId,
            'producto_id' => $this->productoId,
            'clave' => $this->codigoProveedor,
            'codigo_proveedor' => $this->codigoProveedor,
            'cantidad' => $this->cantidad,
            'stock' => $this->stock,
            'stock_cd' => $this->stockCd,
            'precio_unitario' => $this->precioUnitario,
            'subtotal' => $this->getSubtotal()
        ];
    }
}