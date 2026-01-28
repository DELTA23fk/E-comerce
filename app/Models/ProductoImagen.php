<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductoImagen extends Model
{
    use SoftDeletes;

    protected $table = 'producto_imagenes';

    protected $fillable = [
        'url_imagen',
        'producto_id'
    ];
    protected function casts():array
    {
        return [
            'url_imagen' => 'string',
            'producto_id' => 'integer'
        ];
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }
}
