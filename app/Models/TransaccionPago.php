<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TransaccionPago extends Model
{
    use SoftDeletes;

    protected $table = 'transacciones_pagos'; 

    protected $fillable = [
        'pedido_id', 
        'pedido_proveedor_id', 
        'tipo', 
        'gateway',
        'transaction_id', 
        'status', 
        'monto', 
        'moneda', 
        'metadata', 
        'response_data', 
        'motivo'
    ];

    protected $casts = [
        'monto' => 'decimal:2',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function pedido()
    {
        return $this->belongsTo(Pedido::class);
    }

    public function pedidoProveedor()
    {
        return $this->belongsTo(PedidoProveedor::class);
    }

    public function esReembolso(): bool
    {
        return $this->tipo === 'reembolso';
    }

    public function esPago(): bool
    {
        return $this->tipo === 'pago';
    }

    public function esAprobado(): bool
    {
        return $this->status === 'approved';
    }
}