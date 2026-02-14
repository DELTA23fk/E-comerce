<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PedidoProveedor extends Model
{
    use SoftDeletes;

    protected $table = 'pedido_proveedores';

    protected $fillable = [
        'pedido_id', 
        'proveedor_id', 
        'folio_pedido', 
        'precio_total_productos', 
        'precio_total_envio', 
        'precio_total',
        'envio_gratis', 
        'status', 
        'moneda', 
        'email_agente', 
        'email_almacen',
        'origen_envio',
        // Campos de reembolso
        'monto_reembolsado',
        'motivo_reembolso',
        'fecha_reembolso',
        'error_mensaje',
        // Campos de error
        'error_detalle',
        'requiere_atencion_manual',
    ];

    protected $casts = [
        'envio_gratis' => 'boolean',
        'precio_total_productos' => 'decimal:2',
        'precio_total_envio' => 'decimal:2',
        'precio_total' => 'decimal:2',
        'monto_reembolsado' => 'decimal:2',
        'fecha_reembolso' => 'datetime',
        'error_detalle' => 'array',
        'requiere_atencion_manual' => 'boolean',
    ];

    public function pedidoMaestro()
    {
        return $this->belongsTo(Pedido::class, 'pedido_id');
    }

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function detalles()
    {
        return $this->hasMany(DetallePedido::class, 'pedido_proveedor_id');
    }

    public function transacciones()
    {
        return $this->hasMany(TransaccionPago::class);
    }

    public function puedeReembolsarse(): bool
    {
        return in_array($this->status, ['creado', 'procesando', 'fallido']) 
            && $this->monto_reembolsado < $this->precio_total;
    }

    public function montoDisponibleReembolso(): float
    {
        return ($this->precio_total ?? 0) - $this->monto_reembolsado;
    }
}