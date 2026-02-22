<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Medicos;
use App\Models\Turnos;

$id = 10;
echo "Buscando medico ID: $id\n";

$medico = Medicos::find($id);

if (!$medico) {
    echo "No encontrado.\n";
} else {
    echo "ID: {$medico->id_medico}\n";
    echo "Nombres: {$medico->nombres} {$medico->apellidos}\n";
    echo "Estado: {$medico->estado}\n";
    echo "ID Especialidad (Medicos table): {$medico->id_especialidad}\n";
    if ($medico->especialidad) {
        echo "Nombre Especialidad: {$medico->especialidad->nombre_especialidad}\n";
    }

    $turnos = Turnos::where('id_medico', $medico->id_medico)->get();
    echo "Turnos count: " . $turnos->count() . "\n";
    foreach ($turnos as $t) {
        echo "  Turno ID: {$t->id_turno}, Dia: {$t->id_dia}, Estado: {$t->estado}, ID Esp (Turnos table): {$t->id_especialidad}\n";
    }
}
