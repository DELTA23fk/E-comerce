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
        'moneda_cobro_productos',
        'precio_total_productos',
        'moneda_cobro_envio',
        'precio_total_envio',
        'precio_total',
        'iva_incluido',
        'envio_gratis',
        'fecha_entrega_estimada',
        'status',
        'tipo_cambio_aplicado',
        'precio_total_productos_mxn',
        'precio_total_envio_mxn',
        'precio_total_mxn',
        'email_agente',
        'email_almacen',
        'origen_envio',
        // Campos de reembolso
        'monto_reembolsado_mxn',
        'motivo_reembolso',
        'fecha_reembolso',
        'error_mensaje',
        // Campos de error
        'error_detalle',
        'requiere_atencion_manual',
    ];

    protected $casts = [
        'iva_incluido' => 'boolean',
        'envio_gratis' => 'boolean',
        'fecha_entrega_estimada' => 'date',
        'precio_total_productos' => 'decimal:2',
        'precio_total_envio' => 'decimal:2',
        'precio_total' => 'decimal:2',
        'tipo_cambio_aplicado' => 'decimal:4',
        'precio_total_productos_mxn' => 'decimal:2',
        'precio_total_envio_mxn' => 'decimal:2',
        'precio_total_mxn' => 'decimal:2',
        'monto_reembolsado_mxn' => 'decimal:2',
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