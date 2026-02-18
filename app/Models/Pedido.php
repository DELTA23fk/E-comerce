<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Pedido extends Model
{
    use SoftDeletes;

    protected $table = 'pedidos';
    
    protected $fillable = [
        'folio', 
        'fecha_pedido', 
        'precio_total', 
        'precio_total_productos', 
        'precio_total_envio', 
        'estatus', 
        'cliente_id',
        // Campos de pago
        'payment_gateway',
        'payment_id',
        'payment_status',
        'monto_pagado',
        'monto_reembolsado',
        'fecha_pago',
        // Campos de error
        'errores_detallados',
        'requiere_atencion_manual',
    ];

    protected $casts = [
        'fecha_pedido' => 'datetime',
        'fecha_pago' => 'datetime',
        'precio_total' => 'decimal:2',
        'precio_total_productos' => 'decimal:2',
        'precio_total_envio' => 'decimal:2',
        'monto_pagado' => 'decimal:2',
        'monto_reembolsado' => 'decimal:2',
        'errores_detallados' => 'array',
        'requiere_atencion_manual' => 'boolean',
    ];

    public function detalles()
    {
        return $this->hasMany(DetallePedido::class);
    }

    public function pedidosProveedores()
    {
        return $this->hasMany(PedidoProveedor::class, 'pedido_id');
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class);
    }

    public function transacciones()
    {
        return $this->hasMany(TransaccionPago::class);
    }

    public function pagoAprobado(): bool
    {
        return $this->payment_status === 'approved';
    }

    public function requiereReembolso(): bool
    {
        return in_array($this->payment_status, ['approved', 'partial_refunded']) 
            && $this->monto_pagado > $this->monto_reembolsado;
    }

    public function montoDisponibleReembolso(): float
    {
        return $this->monto_pagado - $this->monto_reembolsado;
    }
}