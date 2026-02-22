<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            MedicosSeeder::class,    // 1ro: Crea los médicos (con sus especialidades)
            TurnosSeeder::class,     // 2do: Crea los turnos extrayendo la data de los médicos
            ConsultasSeeder::class,  // 3ro: Crea las consultas extrayendo la data de los médicos
        ]);
    }
}
