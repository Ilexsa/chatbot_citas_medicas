<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Turnos extends Model
{
    //

    public $primaryKey = 'id_turno';
    public $timestamps = false;
    public $table = 'turnos';

    const ACTIVO = 'A';
    const INACTIVO = 'I';

    protected $casts = [
        'hora_ini' => 'datetime:H:i',
        'hora_fin' => 'datetime:H:i',
    ];

    public $fillable = [
        'id_dia',
        'id_consultorio',
        'id_medico',
        'id_especialidad',
        'hora_ini',
        'hora_fin',
        'turno',
        'estado'
    ];

    /**
     * Un turno pertenece a un médico.
     */
    public function medico()
    {
        return $this->belongsTo(Medicos::class, 'id_medico');
    }

    /**
     * Un turno pertenece a un consultorio.
     */
    public function consultorio()
    {
        return $this->belongsTo(Consultorios::class, 'id_consultorio');
    }

    /**
     * Un turno pertenece a una especialidad.
     */
    public function especialidad()
    {
        return $this->belongsTo(Especialidades::class, 'id_especialidad');
    }
}
