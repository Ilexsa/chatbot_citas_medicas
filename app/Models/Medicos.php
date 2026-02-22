<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Especialidades;

class Medicos extends Model
{
    //

    public const ACTIVO = 1;
    public const INACTIVO = 0;

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

    public function especialidad()
    {
        return $this->belongsTo(Especialidades::class, 'id_especialidad');
    }
}
