<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProveedorAlmacen extends Model
{
    use SoftDeletes;
    
    protected $table = 'proveedor_almacenes';

    protected $fillable = [
        'proveedor_id',
        'almacen_id_externo',
        'nombre',
        'codigo_postal',
        'es_principal',
        'es_cd',
    ];
    protected function casts(): array
    {
        return [
            'es_principal' => 'boolean',
            'es_cd'        => 'boolean',
        ];
    }

    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_id');
    }
    public function stock(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AlmacenProductoStock::class, 'almacen_id');
    }

    public function proveedorProductos(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(
            ProveedorProducto::class,
            'almacen_producto_stock',
            'almacen_id',
            'proveedor_producto_id'
        )->withPivot(['cantidad', 'backorder', 'eta_backorder', 'ultima_actualizacion'])
         ->withTimestamps();
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopeDeProveedor($query, int $proveedorId)
    {
        return $query->where('proveedor_id', $proveedorId);
    }

    public function scopePrincipales($query)
    {
        return $query->where('es_principal', true);
    }

    public function scopeCentrosDeDistribucion($query)
    {
        return $query->where('es_cd', true);
    }
}
