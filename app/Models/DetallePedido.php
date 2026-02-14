<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DetallePedido extends Model
{
    use SoftDeletes;
    
    protected $fillable = [
        'pedido_id', 
        'pedido_proveedor_id',
        'proveedor_producto_id', 
        'clave_proveedor', 
        'cantidad', 
        'precio_unitario', 
        'subtotal',
    ];

    protected $casts = [
        'cantidad' => 'integer',
        'precio_unitario' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    public function pedido()
    {
        return $this->belongsTo(Pedido::class);
    }

    public function producto()
    {
        return $this->belongsTo(ProveedorProducto::class, 'proveedor_producto_id');
    }

    public function pedidoProveedor()
    {
        return $this->belongsTo(PedidoProveedor::class, 'pedido_proveedor_id');
    }
}