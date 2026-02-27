<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pacientes extends Model
{
    //

    const INACTIVO = 0;
    const ACTIVO = 1;
    const ELIMINADO = 2;

    public $timestamps = false;

    protected $primaryKey = 'id_paciente';

    protected $table = 'pacientes';

    protected $fillable = [
        'identificacion',
        'nombres',
        'apellidos',
        'rut',
        'estado',
        'tipo_documento',
        'telefono',
    ];
}
