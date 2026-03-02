<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProveedorProducto extends Model
{
    use SoftDeletes;

    protected $table = 'proveedor_productos';

    protected $fillable = [
        'proveedor_producto_id',
        'codigo_proveedor',
        'stock_total',
        'moneda',
        'garantia',
        'ultima_actualizacion',
        'en_oferta',
        'proveedor_id',
        'producto_id'
    ];

    protected function casts():array
    {
        return [
            'proveedor_producto_id' => 'string',
            'codigo_proveedor' => 'string',
            'stock_total' => 'integer',
            'moneda' => 'string',
            'garantia' => 'string',
            'ultima_actualizacion' => 'datetime',
            'proveedor_id' => 'integer',
            'producto_id' => 'integer',
            'en_oferta' => 'boolean'
        ];
    }

    protected $hidden = [
        'created_at',
        'updated_at',
        'deleted_at'
    ];

     public function proveedor()
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }

    /**
     * Relación con precios históricos.
     * Un proveedor puede tener múltiples registros de precio
     * almacenados en la tabla `proveedor_producto_precios`.
     */
    public function precios()
    {
        return $this->hasMany(ProveedorProductoPrecio::class);
    }

    /**
     * Relación rápida al precio más reciente.
     * Se define como hasOne mas ordenado por última actualización.
     */
    public function precio()
    {
        return $this->hasOne(ProveedorProductoPrecio::class)
                    ->latest('ultima_actualizacion');
    }

    public function promociones()
    {
        return $this->hasMany(ProveedorProductoPromocion::class);
    }
    
    public function almacenes()
    {
        return $this->hasMany(AlmacenProductoStock::class, 'proveedor_producto_id');
    }
}
