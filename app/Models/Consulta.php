<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Consulta extends Model
{
    /**
     * El nombre de la tabla asociada con el modelo.
     *
     * @var string
     */
    protected $table = 'consulta';

    /**
     * La clave primaria asociada con la tabla.
     *
     * @var string
     */
    protected $primaryKey = 'id_consulta';

    /**
     * Indica si el modelo debe tener timestamps.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Los atributos que se pueden asignar masivamente.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'id_empresa',
        'id_localidad',
        // 'id_consulta' no se incluye porque es la clave primaria y generalmente no es asignable masivamente.
        'turno',
        'id_medico',
        'id_especialidad',
        'hora_ini',
        'hora_fin',
        'estado',
        'Id_Paciente',
        'fecha_atencion',
        'fecha',
        'hora',
        'fecha_sig',
        'hora_sig',
        'nota_evo',
        'otro_diag',
        'observacion',
        'observacion2',
        'id_certificado',
        'id_diagnostico',
        'temperatura',
        'saturacion',
        'presion',
        'fcardiaca',
        'frespira',
        'peso',
        'talla',
        'masaencef',
        'subtotal',
        'base0',
        'base12',
        'piva',
        'iva',
        'total',
        'fecha_add',
        'fecha_edi',
        'fecha_del',
        'id_usuario_add',
        'id_usuario_edi',
        'id_usuario_del',
        'sec_multineg',
        'sec_cotiza',
        'motivo_solicitud',
        'diag_probable',
        'nota_credito',
        'tar_numero',
        'id_consultorio',
        'dcto',
        'ClienteRuc',
        'Observacion1',
        'procedimiento',
        'halla_clinicos',
        'organo1',
        'organo2',
        'tipo_biopsia',
        'proce_patol',
        'fecha_prox_visita',
        'id_usuario_devol',
        'fecha_devol',
        'id_usuario_open',
        'fecha_open',
    ];
}
