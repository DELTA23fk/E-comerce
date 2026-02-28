<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProveedorProductoPrecio extends Model
{
    use SoftDeletes;

    protected $table = 'proveedor_producto_precios';

    protected $fillable = [
        'moneda_venta',
        'precio_venta',
        'precio_anterior',
        'precio_base_producto',
        'moneda_base_producto',
        'precio_recomendado_proveedor',
        'porcentaje_utilidad',
        'tipo_cambio_usado_mxn',
        'ultima_actualizacion',
        'proveedor_producto_id'
    ];

    protected function casts():array
    {
        return [
            'precio_venta' => 'decimal:2',
            'precio_anterior' => 'decimal:2',
            'precio_base_producto' => 'decimal:2',
            'precio_recomendado_proveedor' => 'decimal:2',
            'porcentaje_utilidad' => 'decimal:2',
            'tipo_cambio_usado_mxn' => 'decimal:2',
            'moneda_venta' => 'string',
            'moneda_base_producto' => 'string',
            'ultima_actualizacion' => 'datetime:d-m-Y H:i:s',
            'proveedor_producto_id' =>'integer'
        ];
    }

    protected $hidden = [
        'created_at',
        'updated_at',
        'deleted_at'
    ];

     public function proveedorProducto()
    {
        return $this->belongsTo(ProveedorProducto::class);
    }


}
