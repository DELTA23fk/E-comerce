<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SubCategoria extends Model
{
    use SoftDeletes;

    protected $table = 'sub_categorias';

    protected $fillable = [
        'nombre'
    ];

    protected function casts():array
    {
        return [
            'nombre' => 'string'
        ];
    }


    public function categories()
    {
        return $this->belongsToMany(
            Categoria::class,
            'categoria_subcategorias', // tabla pivot
            'sub_categoria_id',        // foreign key en pivot
            'categoria_id'             // related key en pivot
        );
    }

    public function productos(){
        return $this->hasMany(Producto::class);
    }
}
