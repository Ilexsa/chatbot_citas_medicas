<?php

namespace App\Http\Controllers;

use App\Http\Requests\TurnosMedicosRequest;
use App\Models\Turnos;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request as FacadesRequest;

class TurnosController extends Controller
{

    /**
     * Devuelve la plantilla de turnos disponibles para los días de la semana
     * correspondientes a la fecha o rango de fechas proporcionado.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function listado(Request $request)
    {
        if (!$request->has('fecha')) {
            return response()->json(['error' => 'El parámetro "fecha" es requerido.'], 400);
        }

        $rangoFechasString = $request->query('fecha');

        $fechas = explode(',', $rangoFechasString);
        $fechaInicioStr = trim($fechas[0]);
        $fechaFinStr = isset($fechas[1]) ? trim($fechas[1]) : $fechaInicioStr;

        try {
            $fechaInicio = Carbon::parse($fechaInicioStr);
            $fechaFin = Carbon::parse($fechaFinStr);
        } catch (\Exception $e) {
            return response()->json(['error' => 'El formato de fecha no es válido. Usa AAAA-MM-DD.'], 400);
        }

        $periodo = CarbonPeriod::create($fechaInicio, $fechaFin);
        $diasDeLaSemana = [];

        foreach ($periodo as $fecha) {
            $dia = $fecha->dayOfWeek + 1;
            if (!in_array($dia, $diasDeLaSemana)) {
                $diasDeLaSemana[] = $dia;
            }
        }

        $turnos = Turnos::query()
            ->whereIn('id_dia', $diasDeLaSemana)
            ->where('estado', 'A')
            ->with(['medico', 'consultorio', 'especialidad'])
            ->orderBy('id_dia', 'asc')
            ->orderBy('hora_ini', 'asc')
            ->get();

        $estado = false;
        $mensaje = 'Ocurrio un error al obtener los turnos';
        if ($turnos->isEmpty()) {
            $mensaje = 'No se encontraron turnos para las fechas seleccionadas';
        } else {
            $estado = true;
            $mensaje = 'Turnos obtenidos correctamente';
        }

        return response()->json([
            'estado' => $estado,
            'mensaje' => $mensaje,
            'data' => $turnos
        ]);
    }

    public function turnosPorMedico(Request $request)
    {
        $idMedico = $request->query('idMedico');
        $nombreMedico = $request->query('nombreMedico');

        // Log::info("Parámetros recibidos - idMedico: $idMedico, nombreMedico: $nombreMedico");
        if (!$request->has('idMedico') && !$request->has('nombreMedico')) {
            return response()->json(['error' => 'Se requiere al menos un parámetro: idMedico o nombreMedico.'], 400);
        }

        $turnos = Turnos::query()
            ->where('estado', 'A')
            ->when($request->has('idMedico'), function ($query) use ($idMedico) {
                return $query->where('id_medico', $idMedico);
            })
            ->when($request->has('nombreMedico'), function ($query) use ($nombreMedico) {
                return $query->whereHas('medico', function ($q) use ($nombreMedico) {
                    $q->whereRaw("CONCAT(nombres, ' ', apellidos) LIKE ?", ["%$nombreMedico%"]);
                });
            })
            ->with(['medico', 'consultorio', 'especialidad'])
            ->orderBy('id_dia', 'asc')
            ->orderBy('hora_ini', 'asc')
            ->get();

            $estado = false;
            $mensaje = 'Ocurrio un error al obtener los turnos';

            if ($turnos->isEmpty()) {
                $mensaje = 'No se encontraron turnos para el médico indicado';
            } else {
                $estado = true;
                $mensaje = 'Turnos obtenidos correctamente';
            }

        return response()->json([
            'estado' => $estado,
            'mensaje' => $mensaje,
            'data' => $turnos
        ]);
    }

    public function store(Request $request){

        $data = [];
        $data['id_dia'] = $request->input('id_dia');
        $data['id_consultorio'] = $request->input('id_consultorio');
        $data['id_medico'] = $request->input('id_medico');
        $data['id_especialidad'] = $request->input('id_especialidad');
        $data['hora_ini'] = $request->input('hora_ini');
        $data['hora_fin'] = $request->input('hora_fin');
        $data['turno'] = $request->input('turno');

        $turno = Turnos::create($data);

        $estado = true;
        $mensaje = 'Turno creado correctamente';

        if (!$turno) {
            $estado = false;
            $mensaje = 'Ocurrió un error al crear el turno';
        }

        return response()->json([
            'estado' => $estado,
            'mensaje' => $mensaje,
            'data' => $turno
        ]);
    }
}
