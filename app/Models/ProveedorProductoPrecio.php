<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProveedorProductoPrecio extends Model
{
    use SoftDeletes;

    protected $table = 'proveedor_producto_precios';

    protected $fillable = [
        'precio_actual',
        'precio_anterior',
        'ultima_actualizacion',
        'proveedor_producto_id'
    ];

    protected function casts():array
    {
        return [
            'precio_actual' => 'decimal:2',
            'precio_anterior' => 'decimal:2',
            'ultima_actualizacion' => 'datetime:d-m-Y H:i:s',
            'proveedor_producto_id' =>'integer'
        ];
    }

     public function proveedorProducto()
    {
        return $this->belongsTo(ProveedorProducto::class);
    }


}
