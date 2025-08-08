<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Especialidades extends Model
{
    //

    public $primaryKey = 'id_especialidad';
    public $timestamps = false;
    public $table = 'especialidades';

    public $fillable = [
        'nombre_especialidad',
        'estado'
    ];
}
