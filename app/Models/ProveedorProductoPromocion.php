<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProveedorProductoPromocion extends Model
{
    use SoftDeletes;

    protected $table = 'proveedor_producto_promociones';

    protected $fillable = [
        // Descuento
        'total_descuento',
        'moneda_descuento',
        'tipo_descuento',

        // Precio en moneda original
        'moneda_precio_original',
        'precio_con_descuento',

        // Equivalente MXN
        'precio_con_descuento_mxn',
        'tipo_cambio_usado',

        // Identificación
        'clave_promocion',
        'descripcion_promocion',

        // Vigencia
        'fecha_inicio',
        'expiracion_fecha',
        'expiracion_texto',

        // Cantidades
        'cantidad_minima',
        'disponible_en_promocion',

        // Precio referencia
        'precio_regular',

        // Estado y relación
        'es_oferta',
        'proveedor_producto_id',
    ];

    protected function casts(): array
    {
        return [
            // Descuento
            'total_descuento'          => 'decimal:2',
            'moneda_descuento'         => 'string',
            'tipo_descuento'           => 'string',

            // Precio original
            'moneda_precio_original'   => 'string',
            'precio_con_descuento'     => 'decimal:4',

            // Equivalente MXN
            'precio_con_descuento_mxn' => 'decimal:4',
            'tipo_cambio_usado'        => 'decimal:4',

            // Identificación
            'clave_promocion'          => 'string',
            'descripcion_promocion'    => 'string',

            // Vigencia
            'fecha_inicio'             => 'date:Y-m-d',
            'expiracion_fecha'         => 'date:Y-m-d',
            'expiracion_texto'         => 'string',

            // Cantidades
            'cantidad_minima'          => 'integer',
            'disponible_en_promocion'  => 'integer',

            // Precio referencia
            'precio_regular'           => 'decimal:4',

            // Estado
            'es_oferta'                => 'boolean',
            'proveedor_producto_id'    => 'integer',
        ];
    }

    protected $hidden = [
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    // =========================================================================
    // RELACIONES
    // =========================================================================

    public function proveedorProducto()
    {
        return $this->belongsTo(ProveedorProducto::class);
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    /**
     * Solo promociones activas.
     */
    public function scopeActivas($query)
    {
        return $query->where('es_oferta', true);
    }

    /**
     * Promociones cuya fecha de expiración ya pasó.
     * Útil para un job de limpieza periódica.
     */
    public function scopeExpiradas($query)
    {
        return $query->whereNotNull('expiracion_fecha')
                     ->where('expiracion_fecha', '<', now()->toDateString());
    }

    /**
     * Promociones con precio en USD que necesitan recalculo de MXN.
     */
    public function scopeEnUsd($query)
    {
        return $query->where('moneda_precio_original', 'USD');
    }

    // =========================================================================
    // ACCESSORS
    // =========================================================================

    /**
     * Devuelve el mejor precio disponible en MXN:
     * - precio_con_descuento_mxn si hubo conversión (vino en USD)
     * - precio_con_descuento si ya venía en MXN
     */
    public function getPrecioMxnAttribute(): ?float
    {
        return $this->precio_con_descuento_mxn ?? $this->precio_con_descuento;
    }

    /**
     * Descripción legible del vencimiento sin importar si es fecha o texto.
     */
    public function getVencimientoAttribute(): ?string
    {
        if ($this->expiracion_fecha) {
            return $this->expiracion_fecha->format('d/m/Y');
        }

        return $this->expiracion_texto;
    }

    /**
     * Indica si la promoción ya venció por fecha.
     */
    public function getEsVencidaAttribute(): bool
    {
        if (!$this->expiracion_fecha) {
            return false;
        }

        return $this->expiracion_fecha->isPast();
    }
}