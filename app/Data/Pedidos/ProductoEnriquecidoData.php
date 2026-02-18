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
        public float $precioOriginal,
        public int $proveedorId,
        public int $productoId,
        public bool $enOferta = false,
        public ?float $descuentoPorcentaje = null,
        public ?string $clavePromocion = null,
        public ?array $metadataProveedor = null, // Datos internos del proveedor (stock, etc.)
    ) {}

    /**
     * Crear desde ProveedorProducto y cantidad solicitada
     * AHORA considera promociones activas automáticamente
     * IMPORTANTE: Solo aplica oferta si hay stock suficiente en disponible_en_promocion
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

        $precioOriginal = (float) $proveedorProducto->pricio->precio_actual;
        $precioFinal = $precioOriginal;
        $enOferta = false;
        $descuentoPorcentaje = null;
        $clavePromocion = null;

        // Buscar promoción activa más ventajosa CON STOCK SUFICIENTE
        $promocionActiva = $proveedorProducto->promociones()
            ->where('es_oferta', true)
            ->whereNotNull('precio_oferta')
            ->where('precio_oferta', '>', 0)
            ->where('disponible_en_promocion', '>=', $cantidad) // ← CRÍTICO: Solo si hay stock
            ->orderBy('precio_oferta', 'asc')
            ->first();

        if ($promocionActiva) {
            $precioFinal = (float) $promocionActiva->precio_oferta;
            $enOferta = true;
            $descuentoPorcentaje = $promocionActiva->total_descuento 
                ? (float) $promocionActiva->total_descuento 
                : round((($precioOriginal - $precioFinal) / $precioOriginal) * 100, 2);
            $clavePromocion = $promocionActiva->clave_promocion;

            \Log::debug('Oferta aplicada', [
                'codigo_proveedor' => $proveedorProducto->codigo_proveedor,
                'cantidad' => $cantidad,
                'clave_promocion' => $clavePromocion,
                'disponible_en_promocion' => $promocionActiva->disponible_en_promocion,
                'precio_original' => $precioOriginal,
                'precio_oferta' => $precioFinal
            ]);
        } else {
            // Verificar si había promociones pero sin stock suficiente
            $promocionesSinStock = $proveedorProducto->promociones()
                ->where('es_oferta', true)
                ->whereNotNull('precio_oferta')
                ->where('precio_oferta', '>', 0)
                ->where('disponible_en_promocion', '<', $cantidad)
                ->where('disponible_en_promocion', '>', 0)
                ->get();

            if ($promocionesSinStock->isNotEmpty()) {
                \Log::info('Oferta no aplicada: stock de promoción insuficiente', [
                    'codigo_proveedor' => $proveedorProducto->codigo_proveedor,
                    'cantidad_solicitada' => $cantidad,
                    'promociones' => $promocionesSinStock->map(fn($p) => [
                        'clave' => $p->clave_promocion,
                        'disponible' => $p->disponible_en_promocion,
                        'precio_oferta' => $p->precio_oferta
                    ])->toArray()
                ]);
            }
        }

        return new self(
            proveedorProductoId: $proveedorProducto->id,
            codigoProveedor: $proveedorProducto->codigo_proveedor,
            cantidad: $cantidad,
            precioUnitario: $precioFinal,
            precioOriginal: $precioOriginal,
            proveedorId: $proveedorProducto->proveedor_id,
            productoId: $proveedorProducto->producto_id,
            enOferta: $enOferta,
            descuentoPorcentaje: $descuentoPorcentaje,
            clavePromocion: $clavePromocion,
            metadataProveedor: [
                'stock' => $proveedorProducto->stock,
                'stock_cd' => $proveedorProducto->stock_cd,
                'moneda' => $proveedorProducto->moneda,
                'garantia' => $proveedorProducto->garantia,
            ]
        );
    }

    public function getSubtotal(): float
    {
        return round($this->cantidad * $this->precioUnitario, 2);
    }

    public function getAhorro(): float
    {
        if (!$this->enOferta) {
            return 0;
        }
        
        return round($this->cantidad * ($this->precioOriginal - $this->precioUnitario), 2);
    }

    /**
     * Formato mínimo para enviar a servicios de proveedor
     * NO expone metadatos internos
     */
    public function toProveedorFormat(): array
    {
        return [
            'proveedor_producto_id' => $this->proveedorProductoId,
            'codigo_proveedor' => $this->codigoProveedor,
            'cantidad' => $this->cantidad,
            'precio_unitario' => $this->precioUnitario,
        ];
    }

    /**
     * Array completo para respuestas al cliente
     */
    public function toArray(): array
    {
        return [
            'proveedor_producto_id' => $this->proveedorProductoId,
            'proveedor_id' => $this->proveedorId,
            'producto_id' => $this->productoId,
            'codigo_proveedor' => $this->codigoProveedor,
            'cantidad' => $this->cantidad,
            'precio_unitario' => $this->precioUnitario,
            'precio_original' => $this->precioOriginal,
            'subtotal' => $this->getSubtotal(),
            'en_oferta' => $this->enOferta,
            'descuento_porcentaje' => $this->descuentoPorcentaje,
            'ahorro' => $this->getAhorro(),
            'clave_promocion' => $this->clavePromocion,
        ];
    }
}