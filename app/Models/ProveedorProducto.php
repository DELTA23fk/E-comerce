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
        'stock_cd',
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
            'stock_cd' => 'integer',
            'moneda' => 'string',
            'garantia' => 'string',
            'ultima_actualizacion' => 'datetime:d-m-Y H:i:s',
            'proveedor_id' => 'integer',
            'producto_id' => 'integer',
            'en_oferta' => 'boolean'
        ];
    }

     public function proveedor()
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }

    public function pricios()
    {
        return $this->hasMany(ProveedorProductoPrecio::class);
    }

    public function promociones()
    {
        return $this->hasMany(ProveedorProductoPromocion::class);
    }
}
