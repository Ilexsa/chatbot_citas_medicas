<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sedes extends Model
{
    //

    public $primaryKey = 'id_sede';
    public $timestamps = false;
    public $table = 'sedes';

    public $fillable = [
        'nombre_sede',
        'descripcion_sede',
        'estado'
    ];
}
