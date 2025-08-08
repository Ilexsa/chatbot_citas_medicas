<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Consultorios extends Model
{
    //

    public $primaryKey = 'id_consultorio';
    public $timestamps = false;
    public $table = 'consultorios';

    public $fillable = [
        'id_localidad',
        'nombre',
        'estado'
    ];

}
