<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Turnos;
use Carbon\Carbon;

class TurnosSeeder extends Seeder
{
    public function run()
    {
        // Generar turnos para los próximos 30 días
        $fechaInicio = Carbon::now();

        for ($i = 0; $i < 100; $i++) {
            $fecha = $fechaInicio->copy()->addDays(rand(0, 30));
            $horaInicio = rand(7, 18); // Horas entre 7am y 6pm
            $minutoInicio = rand(0, 1) ? '00' : '30';

            $turno = [
                'id_dia' => $fecha->dayOfWeek + 1, // Carbon: 0 (domingo) a 6 (sábado)
                'id_consultorio' => rand(1, 20),
                'id_medico' => rand(1, 50),
                'id_especialidad' => rand(1, 20),
                'hora_ini' => $fecha->copy()->setTime($horaInicio, $minutoInicio),
                'hora_fin' => $fecha->copy()->setTime($horaInicio + rand(1, 3), $minutoInicio),
                'turno' => rand(1, 20),
                'estado' => rand(0, 1) ? 'A' : 'I'
            ];

            // Asegurar que la hora de fin sea después de la hora de inicio
            if ($turno['hora_fin'] <= $turno['hora_ini']) {
                $turno['hora_fin']->addHours(1);
            }

            Turnos::create($turno);
        }
    }
}
