<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Mensajes extends Model
{
    protected $table = 'mensajes';

    // public $timestamps = ;

    protected $fillable = [
        'wamid',
        'de',
        'para',
        'mensaje',
        'estado',
        'fecha_envio',
        'created_at',
        'updated_at'
    ];
}
