<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Turnos;
use App\Models\Medicos; // IMPORTANTE: Importar el modelo de Medicos
use Carbon\Carbon;

class TurnosSeeder extends Seeder
{
    public function run()
    {
        $medicos = Medicos::all();

        if ($medicos->isEmpty()) {
            $this->command->info('No hay médicos creados. Ejecuta MedicosSeeder primero.');
            return;
        }

        $fechaInicio = Carbon::now();

        for ($i = 0; $i < 100; $i++) {
            $fecha = $fechaInicio->copy()->addDays(rand(0, 30));
            $horaInicio = rand(7, 18);
            $minutoInicio = rand(0, 1) ? '00' : '30';

            $medicoSeleccionado = $medicos->random();

            $turno = [
                'id_dia' => $fecha->dayOfWeek + 1,
                'id_consultorio' => rand(1, 20),

                'id_medico' => $medicoSeleccionado->id_medico,
                'id_especialidad' => $medicoSeleccionado->id_especialidad,

                'hora_ini' => $fecha->copy()->setTime($horaInicio, $minutoInicio),
                'hora_fin' => $fecha->copy()->setTime($horaInicio + rand(1, 3), $minutoInicio),
                'turno' => rand(1, 20),
                'estado' => rand(0, 1) ? 'A' : 'I'
            ];

            if ($turno['hora_fin'] <= $turno['hora_ini']) {
                $turno['hora_fin']->addHours(1);
            }

            Turnos::create($turno);
        }
    }
}
