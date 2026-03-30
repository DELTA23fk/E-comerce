<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Collaborator extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'avatar_url',
        'imagen_url',
        'position',
        'joined_at',
        'left_at'
    ];

    protected function casts()
    {
        return [
            'joined_at' => 'date',
            'left_at' => 'date',
            'name' => 'string',
            'description' => 'string',
            'avatar_url' => 'string',
            'image_url' => 'string',
            'position' => 'string',
        ];
    }

    
}
