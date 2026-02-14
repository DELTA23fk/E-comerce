<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProveedorEstado extends Model
{
    // use SoftDeletes;
    
    protected $table = 'proveedor_estados';

    protected $fillable = [
        'proveedor_id',
        'clave',
        'descripcion'
    ];

    /**
     * Relación con el Proveedor (Padre)
     */
    public function proveedor()
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_id');
    }

    /**
     * Relación con las Ciudades (Hijos)
     */
    public function ciudades()
    {
        return $this->hasMany(ProveedorEstadoCiudad::class, 'proveedor_estado_id');
    }
}
