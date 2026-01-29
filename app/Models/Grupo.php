<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Grupo extends Model
{
    use SoftDeletes;

    protected $table = 'grupos';

    protected $fillable = [
        'nombre'
    ];
    protected function casts():array
    {
        return [
            'nombre' => 'string'
        ];
    }

    protected $hidden = [
        'created_at',
        'updated_at',
        'deleted_at'
    ];
    

    public function productos(){
        return $this->hasMany(Producto::class);
    }
}
