<?php

namespace Database\Seeders;

use App\Models\Pacientes;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Faker\Factory as Faker;

class PacientesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //

        $faker = Faker::create('es_ES');

        for ($i = 0; $i < 50; $i++) {

            $dataPacientes = [
                'identificacion' => $faker->numerify('##########'),
                'tipo_documento' => $faker->randomElement(['CC', 'TI', 'CE', 'PASAPORTE']),
                'nombres' => $faker->firstName(),
                'apellidos' => $faker->lastName(),
                'rut' => $faker->numerify('########-#'),
                'estado' => $faker->randomElement([Pacientes::ACTIVO, Pacientes::INACTIVO, Pacientes::ELIMINADO]),
            ];

            Pacientes::create(
                $dataPacientes
            );
        }
    }
}
