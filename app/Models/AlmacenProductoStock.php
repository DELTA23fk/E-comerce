<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AlmacenProductoStock extends Model
{
    use SoftDeletes;

    protected $table = 'almacen_producto_stock';

    protected $fillable = [
        'proveedor_almacen_id',
        'proveedor_producto_id',
        'cantidad',
        'backorder',
        'eta_backorder',
        'ultima_actualizacion',
    ];

    protected function casts(): array
    {
        return [
            'cantidad'             => 'integer',
            'backorder'            => 'integer',
            'eta_backorder'        => 'date:Y-m-d',
            'ultima_actualizacion' => 'datetime',
        ];
    }

    protected $hidden = ['created_at', 'updated_at', 'deleted_at'];

    // =========================================================================
    // RELACIONES
    // =========================================================================

    public function almacen(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ProveedorAlmacen::class);
    }

    public function proveedorProducto(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ProveedorProducto::class);
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopeConStock($query)
    {
        return $query->where('cantidad', '>', 0);
    }

    public function scopeConBackorder($query)
    {
        return $query->whereNotNull('backorder')->where('backorder', '>', 0);
    }

    // =========================================================================
    // ACCESSORS
    // =========================================================================

    public function getTieneStockAttribute(): bool
    {
        return $this->cantidad > 0;
    }

    public function getTieneBackorderAttribute(): bool
    {
        return $this->backorder !== null && $this->backorder > 0;
    }
}
