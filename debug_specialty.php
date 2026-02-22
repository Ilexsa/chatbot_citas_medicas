<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Especialidades;
use App\Models\Turnos;

$term = 'medicina general';
echo "Buscando especialidad: $term\n";

$especialidades = Especialidades::where('nombre_especialidad', 'ilike', "%$term%")->get();

if ($especialidades->isEmpty()) {
    echo "No se encontraron especialidades.\n";
} else {
    foreach ($especialidades as $esp) {
        echo "ID: {$esp->id_especialidad}, Nombre: {$esp->nombre_especialidad}\n";
        
        // Check shifts for this specialty
        $turnos = Turnos::whereHas('medico', function($q) use ($esp) {
            $q->where('id_especialidad', $esp->id_especialidad);
        })->where('estado', 'A')->get();
        
        echo "  Turnos activos encontrados: " . $turnos->count() . "\n";
        foreach ($turnos->groupBy('id_dia') as $dia => $group) {
            echo "    Dia $dia: " . $group->count() . " turnos\n";
        }
    }
}
