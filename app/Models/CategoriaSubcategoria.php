<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CategoriaSubcategoria extends Model
{
    use SoftDeletes;
    
    protected $table = 'categoria_subcategorias';

    protected $fillable = [
        'categoria_id',
        'sub_categoria_id'
    ];
}
