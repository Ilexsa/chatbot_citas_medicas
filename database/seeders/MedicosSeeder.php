<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Medicos;
use Carbon\Carbon;

class MedicosSeeder extends Seeder
{
    public function run()
    {
        $nombresMasculinos = [
            'Juan', 'Carlos', 'Luis', 'Pedro', 'Andrés', 'Miguel', 'Jorge', 'Fernando',
            'Ricardo', 'Daniel', 'Roberto', 'Santiago', 'Felipe', 'Alejandro', 'Rafael'
        ];

        $nombresFemeninos = [
            'María', 'Ana', 'Laura', 'Carolina', 'Sofía', 'Isabella', 'Valentina', 'Camila',
            'Gabriela', 'Patricia', 'Diana', 'Lucía', 'Ximena', 'Paula', 'Adriana'
        ];

        $apellidos = [
            'Gómez', 'Rodríguez', 'González', 'López', 'Martínez', 'Pérez', 'Sánchez',
            'Ramírez', 'Torres', 'Flórez', 'Hernández', 'Díaz', 'Moreno', 'Muñoz', 'Rojas'
        ];

        for ($i = 0; $i < 50; $i++) {
            $sexo = rand(0, 1) ? 'M' : 'F';

            $medico = [
                'identificacion' => $this->generarIdentificacion(),
                'nombres' => $sexo === 'M'
                    ? $nombresMasculinos[array_rand($nombresMasculinos)]
                    : $nombresFemeninos[array_rand($nombresFemeninos)],
                'apellidos' => $apellidos[array_rand($apellidos)] . ' ' . $apellidos[array_rand($apellidos)],
                'sexo' => $sexo,
                'fecha_nacimiento' => $this->generarFechaNacimiento(),
                'id_especialidad' => rand(1, 20),
                'estado' => rand(0, 1) ? Medicos::ACTIVO : Medicos::INACTIVO
            ];

            Medicos::create($medico);
        }
    }

    private function generarIdentificacion()
    {
        return strval(rand(10000000, 99999999));
    }

    private function generarFechaNacimiento()
    {
        $fechaInicio = Carbon::now()->subYears(65);
        $fechaFin = Carbon::now()->subYears(30);

        return Carbon::createFromTimestamp(rand(
            $fechaInicio->timestamp,
            $fechaFin->timestamp
        ))->format('Y-m-d');
    }
}
