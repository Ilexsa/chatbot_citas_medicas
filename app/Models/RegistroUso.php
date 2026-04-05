<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegistroUso extends Model
{
    protected $table = 'registros_uso';

    protected $fillable = [
        'telefono_usuario',
        'tokens_entrada',
        'tokens_salida',
        'iteraciones_ia',
        'fecha',
    ];

    protected $casts = [
        'fecha' => 'date',
    ];
}
