<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Producto extends Model
{
    use SoftDeletes;

    protected $table = 'productos';

    protected $fillable = [
        'codigo_fabricante',
        'codigo_barras',
        'upc',
        'nombre',
        'descripcion',
        'descripcion_tecnica',
        'marca_id',
        'categoria_id',
        'familia_id',
        'grupo_id',
        'sub_categoria_id'
    ];
    protected function casts(): array
    {
        return  [
            'codigo_fabricante' => 'string',
            'codigo_barras' => 'string',
            'upc' => 'string',
            'nombre' => 'string',
            'descripcion' => 'string',
            'descripcion_tecnica' => 'string',
            'marca_id' => 'integer',
            'categoria_id' => 'integer',
            'familia_id' => 'integer',
            'grupo_id' => 'integer',
            'sub_categoria_id' => 'integer'
        ];
    }

    public function categoria()
    {
        return $this->belongsTo(Categoria::class);
    }
    public function subCategoria()
    {
        return $this->belongsTo(SubCategoria::class);
    }
    public function familia(){
        return $this->belongsTo(Familia::class);
    }
    public function grupo(){
        return $this->belongsTo(Grupo::class);
    }

    public function imagenes()
    {
        return $this->hasMany(ProductoImagen::class);
    }
}
