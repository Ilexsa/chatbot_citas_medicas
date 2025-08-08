<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Medicos extends Model
{
    //

    public $primaryKey = 'id_medico';
    public $timestamps = false;
    public $table = 'medicos';

    public $fillable = [
        'identificacion',
        'nombres',
        'apellidos',
        'sexo',
        'fecha_nacimiento',
        'id_especialidad',
        'estado'
    ];
}
