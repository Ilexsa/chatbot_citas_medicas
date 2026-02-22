<?php

namespace App\Http\Controllers;

use App\Models\Consulta;
use App\Models\Turnos;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;

class ConsultasController extends Controller
{

    public function listadoConsultasPorEspecialidad(Request $request)
    {
        $especialidadIds = $request->query('id_especialidad');
        $fechaRango = $request->query('fecha');
        $fechaInicio = Carbon::parse($fechaRango[0]);
        $fechaFin = Carbon::parse($fechaRango[1]);

        $duracionConsulta = 30;

        $diasSemana = [];
        foreach (CarbonPeriod::create($fechaInicio, $fechaFin) as $fecha) {
            $diasSemana[] = $fecha->dayOfWeekIso;
        }
        $diasSemana = array_unique($diasSemana);

        $turnosMedicos = Turnos::whereIn('id_especialidad', $especialidadIds)
            ->whereIn('id_dia', $diasSemana)
            ->where('estado', Turnos::ACTIVO)
            ->where('turno', '!=', 0)
            ->get([
                'id_medico',
                'id_dia',
                'hora_inicio',
                'hora_fin'
            ])->groupBy('id_medico');

        $idMedicosDisponibles = $turnosMedicos->keys();

        $consultasOcupadas = Consulta::whereIn('id_medico', $idMedicosDisponibles)
            ->whereBetween('fecha', [$fechaInicio->toDateString(), $fechaFin->toDateString()])
            ->whereIn('estado', [Consulta::PENDIENTE, Consulta::ATENDIDA, Consulta::AGENDADA])
            ->get(['id_medico', 'fecha', 'hora_ini', 'hora_fin'])
            ->groupBy('id_medico');

        $disponibilidadFinal = [];

        foreach ($turnosMedicos as $idMedico => $turnos) {
            $horariosLibresMedico = [];

            foreach (CarbonPeriod::create($fechaInicio, $fechaFin) as $dia) {
                $idDiaSemana = $dia->dayOfWeekIso;
                $fechaActualStr = $dia->toDateString();

                $turnoDelDia = $turnos->firstWhere('id_dia', $idDiaSemana);

                if ($turnoDelDia) {
                    $slotsPotenciales = [];
                    $horaInicioTurno = Carbon::parse($turnoDelDia->hora_inicio);
                    $horaFinTurno = Carbon::parse($turnoDelDia->hora_fin);

                    while ($horaInicioTurno < $horaFinTurno) {
                        $slotsPotenciales[] = $horaInicioTurno->format('H:i');
                        $horaInicioTurno->addMinutes($duracionConsulta);
                    }

                    $slotsOcupados = [];
                    if (isset($consultasOcupadas[$idMedico])) {
                        $consultasDelDia = $consultasOcupadas[$idMedico]->where('fecha', $fechaActualStr);
                        foreach ($consultasDelDia as $consulta) {
                            $horaInicioConsulta = Carbon::parse($consulta->hora_ini);
                            $horaFinConsulta = Carbon::parse($consulta->hora_fin);

                            while ($horaInicioConsulta < $horaFinConsulta) {
                                $slotsOcupados[] =  $horaInicioConsulta->format('H:i');
                                $horaInicioConsulta->addMinutes($duracionConsulta);
                            }
                        }
                    }

                    $slotsLibres = array_diff($slotsPotenciales, $slotsOcupados);

                    if (!empty($slotsLibres)) {
                        $horariosLibresMedico[$fechaActualStr] = array_values($slotsLibres);
                    }
                }
            }

            if (!empty($horariosLibresMedico)) {
                $disponibilidadFinal[] = [
                    'id_medico' => $idMedico,
                    'disponibilidad' => $horariosLibresMedico
                ];
            }
        }

        return response()->json($disponibilidadFinal);
    }

    public function listadoConsultasPorMedico(Request $request) {}
}
