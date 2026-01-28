<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProveedorProductoPromocion extends Model
{
    use SoftDeletes;

    protected $table = 'proveedor_producto_promociones';

    protected $fillable = [
        'total_descuento',
        'moneda_descuento',
        'precio_con_descuento',
        'clave_promocion',
        'descripcion_promocion',
        'expracion',
        'disponible_en_promocion',
        'precio_oferta',
        'precio_regular',
        'proveedor_producto_id'
    ];

    protected function casts():array
    {
        return [
            'total_descuento' => 'decimal:2',
            'moneda_descuento' => 'string',
            'precio_con_descuento' => 'decimal:2',
            'clave_promocion' => 'string',
            'descripcion_promocion' => 'string',
            'expracion' => 'string',
            'disponible_en_promocion' => 'integer',
            'precio_oferta' => 'decimal:2',
            'precio_regular' => 'decimal:2',
            'proveedor_producto_id' => 'integer'
        ];
    }

    public function proveedorProducto()
    {
        return $this->belongsTo(ProveedorProducto::class);
    }
}
