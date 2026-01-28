<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Categoria extends Model
{
    use SoftDeletes;

    protected $table = 'categorias';

    protected $fillable = [
        'nombre'
    ];

    protected function casts():array
    {
        return [
            'nombre' => 'string'
        ];
        
    }

     public function subCategorias()
    {
        return $this->belongsToMany(
            SubCategoria::class,
            'categoria_subcategorias', // tabla pivot
            'categora_id',             // foreign key en pivot
            'sub_categoria_id'          // related key en pivot
        );
    }

    public function productos(){
        return $this->hasMany(Producto::class);
    }
}
