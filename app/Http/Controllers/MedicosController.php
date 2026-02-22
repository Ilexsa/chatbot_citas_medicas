<?php

namespace App\Http\Controllers;

use App\Models\Medicos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MedicosController extends Controller
{


    public function listado()
    {
        $medicos = Medicos::selectRaw("id_medico, identificacion, CONCAT(nombres, ' ', apellidos) as nombre_completo,  nombre_especialidad, medicos.estado")
            ->join('especialidades', 'medicos.id_especialidad', '=', 'especialidades.id_especialidad')
            ->where('medicos.estado', Medicos::ACTIVO)
            ->get();

        Log::info("Listado de médicos: " . $medicos->toJson());

        $estado = 'success';
        $mensaje = 'Listado de médicos obtenido correctamente';

        if ($medicos == null) {
            $estado = 'error';
            $mensaje = 'No se encontraron médicos';
        }

        //Log::info("Listado de médicos: " . $medicos->toJson());

        return response()->json([
            'estado' => $estado,
            'mensaje' => $mensaje,
            'data' => $medicos
        ]);
    }

    public function medicosEspecialidad($idEspecialidad)
    {
        $medicos = Medicos::selectRaw("id_medico, identificacion, CONCAT(nombres, ' ', apellidos) as nombre_completo,  nombre_especialidad, medicos.estado")
            ->join('especialidades', 'medicos.id_especialidad', '=', 'especialidades.id_especialidad')
            ->where('medicos.estado', Medicos::ACTIVO)
            ->where('medicos.id_especialidad', $idEspecialidad)
            ->get();

        $estado = 'success';
        $mensaje = 'Listado de médicos por especialidad obtenido correctamente';

        if ($medicos == null) {
            $estado = 'error';
            $mensaje = 'No se encontraron médicos para la especialidad indicada';
        }

        return response()->json([
            'estado' => $estado,
            'mensaje' => $mensaje,
            'data' => $medicos
        ]);
    }
}
