<?php

namespace App\Data\Pedidos;

use App\Models\ProveedorProducto;

readonly class ProductoEnriquecidoData
{
    public function __construct(
        public int     $proveedorProductoId,
        public string  $codigoProveedor,
        public int     $cantidad,
        public float   $precioUnitario,
        public float   $precioOriginal,
        public int     $proveedorId,
        public int     $productoId,
        public bool    $enOferta            = false,
        public ?float  $descuentoPorcentaje = null,
        public ?string $clavePromocion      = null,
        public ?array  $metadataProveedor   = null,
    ) {}

    /**
     * Crear desde ProveedorProducto y cantidad solicitada.
     *
     * CAMBIOS ESQUEMA NUEVO:
     * - precio_oferta eliminado → precio efectivo = precio_con_descuento_mxn ?? precio_con_descuento
     * - moneda_precio_original determina si el precio ya está en MXN o fue convertido
     * - La query ordena por COALESCE(precio_con_descuento_mxn, precio_con_descuento) ASC
     *   para encontrar siempre la promoción más ventajosa en MXN
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

        $precioOriginal      = (float) $proveedorProducto->pricio->precio_venta;
        $precioFinal         = $precioOriginal;
        $enOferta            = false;
        $descuentoPorcentaje = null;
        $clavePromocion      = null;

        // ── Buscar la promoción activa más ventajosa CON stock suficiente ─────
        // Precio efectivo = precio_con_descuento_mxn si vino en USD (ya convertido),
        //                   precio_con_descuento si ya venía en MXN
        $promocionActiva = $proveedorProducto->promociones()
            ->where('es_oferta', true)
            ->where(function ($q) {
                // Tiene precio válido en al menos una de las dos columnas
                $q->where(function ($q2) {
                    $q2->whereNotNull('precio_con_descuento_mxn')
                       ->where('precio_con_descuento_mxn', '>', 0);
                })->orWhere(function ($q2) {
                    $q2->whereNull('precio_con_descuento_mxn')
                       ->whereNotNull('precio_con_descuento')
                       ->where('precio_con_descuento', '>', 0);
                });
            })
            ->where('disponible_en_promocion', '>=', $cantidad)
            ->orderByRaw('COALESCE(precio_con_descuento_mxn, precio_con_descuento) ASC')
            ->first();

        if ($promocionActiva) {
            // Usar siempre el precio en MXN para coherencia con el resto del sistema
            $precioFinal = (float) ($promocionActiva->precio_con_descuento_mxn
                ?? $promocionActiva->precio_con_descuento);

            $enOferta   = true;
            $clavePromocion = $promocionActiva->clave_promocion;

            // Calcular descuento desde total_descuento o derivarlo de precios
            $descuentoPorcentaje = $promocionActiva->total_descuento
                ? (float) $promocionActiva->total_descuento
                : ($precioOriginal > 0
                    ? round((($precioOriginal - $precioFinal) / $precioOriginal) * 100, 2)
                    : null);

            \Log::debug('Oferta aplicada', [
                'codigo_proveedor'         => $proveedorProducto->codigo_proveedor,
                'cantidad'                 => $cantidad,
                'clave_promocion'          => $clavePromocion,
                'disponible_en_promocion'  => $promocionActiva->disponible_en_promocion,
                'moneda_original'          => $promocionActiva->moneda_precio_original,
                'precio_con_descuento'     => $promocionActiva->precio_con_descuento,
                'precio_con_descuento_mxn' => $promocionActiva->precio_con_descuento_mxn,
                'tipo_cambio_usado'        => $promocionActiva->tipo_cambio_usado,
                'precio_aplicado_mxn'      => $precioFinal,
                'precio_original'          => $precioOriginal,
                'descuento_porcentaje'     => $descuentoPorcentaje,
            ]);

        } else {
            // Verificar si había promociones pero sin stock suficiente para logging
            $promocionesSinStock = $proveedorProducto->promociones()
                ->where('es_oferta', true)
                ->where(function ($q) {
                    $q->where(function ($q2) {
                        $q2->whereNotNull('precio_con_descuento_mxn')
                           ->where('precio_con_descuento_mxn', '>', 0);
                    })->orWhere(function ($q2) {
                        $q2->whereNull('precio_con_descuento_mxn')
                           ->whereNotNull('precio_con_descuento')
                           ->where('precio_con_descuento', '>', 0);
                    });
                })
                ->where('disponible_en_promocion', '>', 0)
                ->where('disponible_en_promocion', '<', $cantidad)
                ->get();

            if ($promocionesSinStock->isNotEmpty()) {
                \Log::info('Oferta no aplicada: stock de promoción insuficiente', [
                    'codigo_proveedor'    => $proveedorProducto->codigo_proveedor,
                    'cantidad_solicitada' => $cantidad,
                    'promociones'         => $promocionesSinStock->map(fn($p) => [
                        'clave'                    => $p->clave_promocion,
                        'disponible_en_promocion'  => $p->disponible_en_promocion,
                        'moneda_original'          => $p->moneda_precio_original,
                        'precio_con_descuento'     => $p->precio_con_descuento,
                        'precio_con_descuento_mxn' => $p->precio_con_descuento_mxn,
                        'tipo_cambio_usado'        => $p->tipo_cambio_usado,
                    ])->toArray(),
                ]);
            }
        }

        return new self(
            proveedorProductoId: $proveedorProducto->id,
            codigoProveedor:     $proveedorProducto->codigo_proveedor,
            cantidad:            $cantidad,
            precioUnitario:      $precioFinal,
            precioOriginal:      $precioOriginal,
            proveedorId:         $proveedorProducto->proveedor_id,
            productoId:          $proveedorProducto->producto_id,
            enOferta:            $enOferta,
            descuentoPorcentaje: $descuentoPorcentaje,
            clavePromocion:      $clavePromocion,
            metadataProveedor:   [
                'stock'    => $proveedorProducto->stock,
                'stock_cd' => $proveedorProducto->stock_cd,
                'moneda'   => $proveedorProducto->moneda,
                'garantia' => $proveedorProducto->garantia,
            ],
        );
    }

    // =========================================================================
    // MÉTODOS
    // =========================================================================

    public function getSubtotal(): float
    {
        return round($this->cantidad * $this->precioUnitario, 2);
    }

    public function getAhorro(): float
    {
        if (!$this->enOferta) return 0.0;

        return round($this->cantidad * ($this->precioOriginal - $this->precioUnitario), 2);
    }

    /**
     * Formato mínimo para enviar a servicios del proveedor.
     * No expone metadatos internos.
     */
    public function toProveedorFormat(): array
    {
        return [
            'proveedor_producto_id' => $this->proveedorProductoId,
            'codigo_proveedor'      => $this->codigoProveedor,
            'cantidad'              => $this->cantidad,
            'precio_unitario'       => $this->precioUnitario,
        ];
    }

    /**
     * Array completo para respuestas al cliente.
     */
    public function toArray(): array
    {
        return [
            'proveedor_producto_id' => $this->proveedorProductoId,
            'proveedor_id'          => $this->proveedorId,
            'producto_id'           => $this->productoId,
            'codigo_proveedor'      => $this->codigoProveedor,
            'cantidad'              => $this->cantidad,
            'precio_unitario'       => $this->precioUnitario,
            'precio_original'       => $this->precioOriginal,
            'subtotal'              => $this->getSubtotal(),
            'en_oferta'             => $this->enOferta,
            'descuento_porcentaje'  => $this->descuentoPorcentaje,
            'ahorro'                => $this->getAhorro(),
            'clave_promocion'       => $this->clavePromocion,
        ];
    }
}