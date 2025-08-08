<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Turnos extends Model
{
    //

    public $primaryKey = 'id_turno';
    public $timestamps = false;
    public $table = 'turnos';

    public $fillable = [
        'id_dia',
        'id_consultorio',
        'id_medico',
        'id_especialidad',
        'hora_ini',
        'hora_fin',
        'turno'
    ];
}
