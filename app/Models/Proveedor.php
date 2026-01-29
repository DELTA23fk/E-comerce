<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Proveedor extends Model
{
    use SoftDeletes;
    
    protected $table = 'proveedores';

    protected $fillable = [
        'codigo_proveedor',
        'nombre',
        'activo'
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
        'deleted_at'
    ];
    
    protected function casts(): array
    {
        return [
            'codigo_proveedor' => 'string',
            'nombre' => 'string',
            'activo' => 'boolean'
        ];
    }

    public function productoProveedor(){
        return $this->hasMany(ProveedorProducto::class);
    }
    
    
}
