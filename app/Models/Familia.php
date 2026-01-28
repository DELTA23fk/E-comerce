<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Familia extends Model
{
    use SoftDeletes;

    protected $table = 'familias';

    protected $fillable = [
        'nombre'
    ];
    protected function casts():array
    {
        return [
            'nombre' => 'string'
        ];
    }
    
    public function productos(){
        return $this->hasMany(Producto::class);
    }
}
