<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Medicos;
use App\Models\Turnos;

$term = 'Torres';
echo "Buscando medico con term: $term\n";

$medicos = Medicos::where('nombres', 'ilike', "%$term%")
    ->orWhere('apellidos', 'ilike', "%$term%")
    ->get();

foreach ($medicos as $m) {
    echo "ID: {$m->id_medico}, Nombre: {$m->nombres} {$m->apellidos}, ID Esp: {$m->id_especialidad}\n";
    if ($m->especialidad) {
        echo "  Especialidad: {$m->especialidad->nombre_especialidad}\n";
    }
    
    // Check turns for this doctor
    $turnos = Turnos::where('id_medico', $m->id_medico)->where('estado', 'A')->get();
    echo "  Turnos activos: " . $turnos->count() . "\n";
    foreach ($turnos as $t) {
        echo "    Turno ID: {$t->id_turno}, Dia: {$t->id_dia}, Hora: {$t->hora_ini} - {$t->hora_fin}\n";
    }
}
