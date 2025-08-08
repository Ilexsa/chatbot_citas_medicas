<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Consultorios;

class ConsultoriosSeeder extends Seeder
{
    public function run()
    {
        $nombresConsultorios = [
            'Consulta General 1',
            'Pediatría Central',
            'Cardiología Norte',
            'Oftalmología Sur',
            'Dermatología Este',
            'Traumatología Oeste',
            'Consulta de Emergencias',
            'Oncología Integral',
            'Neurología Avanzada',
            'Ginecología y Obstetricia',
            'Consulta de Vacunación',
            'Rehabilitación Física',
            'Consulta Prenatal',
            'Endocrinología',
            'Consulta Geriátrica',
            'Alergología',
            'Nefrología',
            'Consulta de Salud Mental',
            'Reumatología',
            'Consulta de Nutrición'
        ];

        $estados = ['A', 'I']; // A = Activo, I = Inactivo

        foreach ($nombresConsultorios as $nombre) {
            $consultorio = [
                'id_localidad' => 1,
                'nombre' => $nombre,
                'estado' => $estados[array_rand($estados)]
            ];

            Consultorios::create($consultorio);
        }
    }
}
