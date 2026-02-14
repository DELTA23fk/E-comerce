<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProveedorEstadoCiudad extends Model
{
    // use SoftDeletes;
    
    protected $table = 'proveedor_estado_ciudades';

    protected $fillable = [
        'proveedor_estado_id',
        'clave',
        'descripcion'
    ];

    /**
     * Relación con el Estado (Padre)
     */
    public function estado()
    {
        return $this->belongsTo(ProveedorEstado::class, 'proveedor_estado_id');
    }
}
