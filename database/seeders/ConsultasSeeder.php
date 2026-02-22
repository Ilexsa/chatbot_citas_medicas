<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Consulta;
use App\Models\Medicos; // IMPORTANTE: Importar el modelo de Medicos
use Carbon\Carbon;
use Faker\Factory as Faker;

class ConsultasSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faker = Faker::create('es_ES');

        $medicos = Medicos::all();

        if ($medicos->isEmpty()) {
            $this->command->info('No hay médicos creados. Ejecuta MedicosSeeder primero.');
            return;
        }

        for ($i = 0; $i < 50; $i++) {

            // Seleccionamos un médico aleatorio de la colección
            $medicoSeleccionado = $medicos->random();

            $datosBase = [
                'id_empresa'      => $faker->numberBetween(1, 3),
                'id_localidad'    => $faker->numberBetween(1, 5),
                'turno'           => $faker->numberBetween(1, 2),

                'id_medico'       => $medicoSeleccionado->id_medico,
                'id_especialidad' => $medicoSeleccionado->id_especialidad,

                'id_paciente'     => $faker->numerify('##########'),
                'id_consultorio'  => $faker->numberBetween(1, 20),
                'fecha_add'       => Carbon::now(),
                'id_usuario_add'  => 'SEEDER',
            ];

            $estados = [Consulta::AGENDADA, Consulta::CANCELADA, Consulta::ATENDIDA, Consulta::FACTURADA];
            $estadoSeleccionado = $faker->randomElement($estados);

            $datosBase['estado'] = $estadoSeleccionado;
            $datosEspecificos = [];

            switch ($estadoSeleccionado) {
                case Consulta::AGENDADA:
                    $fechaCita = Carbon::now()->addDays($faker->numberBetween(1, 30));
                    $horaCita = $faker->time('H:i');
                    $datosEspecificos = [
                        'fecha' => $fechaCita,
                        'hora' => $horaCita,
                        'hora_ini' => $horaCita,
                        'hora_fin' => Carbon::parse($horaCita)->addMinutes(30)->format('H:i'),
                        'observacion' => 'Paciente agendado para revisión general.',
                    ];
                    break;

                case Consulta::ATENDIDA:
                    $fechaCita = Carbon::now()->subDays($faker->numberBetween(1, 60));
                    $horaCita = $faker->time('H:i');
                    $datosEspecificos = [
                        'fecha' => $fechaCita,
                        'fecha_atencion' => $fechaCita,
                        'hora' => $horaCita,
                        'hora_ini' => $horaCita,
                        'hora_fin' => Carbon::parse($horaCita)->addMinutes(30)->format('H:i'),
                        'nota_evo' => $faker->paragraph(3),
                        'id_diagnostico' => $faker->lexify('???##'),
                        'otro_diag' => 'Sin otros diagnósticos',
                        'observacion' => $faker->sentence(),
                        'temperatura' => $faker->randomFloat(1, 36, 38) . ' °C',
                        'saturacion' => $faker->numberBetween(95, 100) . ' %',
                        'presion' => $faker->numberBetween(110, 130) . '/' . $faker->numberBetween(70, 90),
                        'fcardiaca' => $faker->numberBetween(60, 100) . ' lpm',
                        'frespira' => $faker->numberBetween(12, 20) . ' rpm',
                        'peso' => $faker->randomFloat(2, 60, 90) . ' kg',
                        'talla' => $faker->randomFloat(2, 1.5, 1.9) . ' m',
                        'halla_clinicos' => $faker->sentence(10),
                        'fecha_edi' => Carbon::now(),
                        'id_usuario_edi' => 'SEEDER_ATN',
                    ];
                    break;

                case Consulta::FACTURADA:
                    $fechaCita = Carbon::now()->subDays($faker->numberBetween(1, 180));
                    $horaCita = $faker->time('H:i');
                    $base12 = $faker->randomFloat(2, 20, 150);
                    $iva = $base12 * 0.12;
                    $total = $base12 + $iva;

                    $datosEspecificos = [
                        'fecha' => $fechaCita,
                        'fecha_atencion' => $fechaCita,
                        'hora' => $horaCita,
                        'hora_ini' => $horaCita,
                        'hora_fin' => Carbon::parse($horaCita)->addMinutes(30)->format('H:i'),
                        'nota_evo' => $faker->paragraph(4),
                        'id_diagnostico' => $faker->lexify('???##'),
                        'temperatura' => $faker->randomFloat(1, 36, 38) . ' °C',
                        'saturacion' => $faker->numberBetween(95, 100) . ' %',
                        'presion' => '120/80',
                        'peso' => '75 kg',
                        'talla' => '1.75 m',
                        'subtotal' => $base12,
                        'base0' => 0,
                        'base12' => $base12,
                        'piva' => 12,
                        'iva' => round($iva, 2),
                        'total' => round($total, 2),
                        'ClienteRuc' => $faker->numerify('#############'),
                        'fecha_edi' => Carbon::now(),
                        'id_usuario_edi' => 'SEEDER_FAC',
                    ];
                    break;

                case Consulta::CANCELADA:
                    $fechaCita = Carbon::now()->subDays($faker->numberBetween(2, 10));
                    $horaCita = $faker->time('H:i');
                    $datosEspecificos = [
                        'fecha' => $fechaCita,
                        'hora' => $horaCita,
                        'observacion' => 'Paciente canceló la cita con 24 horas de antelación.',
                        'fecha_del' => Carbon::now(),
                        'id_usuario_del' => 'USER_CANCEL',
                    ];
                    break;
            }

            Consulta::create(array_merge($datosBase, $datosEspecificos));
        }
    }
}
