<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Turnos;

echo "Contando turnos activos total:\n";
$count = Turnos::where('estado', 'A')->count();
echo "Total: $count\n";

if ($count > 0) {
    echo "Primeros 10 turnos activos:\n";
    $turnos = Turnos::where('estado', 'A')->with(['medico', 'especialidad'])->take(10)->get();
    foreach ($turnos as $t) {
        $medico = $t->medico ? $t->medico->nombres . ' ' . $t->medico->apellidos : 'ID ' . $t->id_medico;
        $esp = $t->especialidad ? $t->especialidad->nombre_especialidad : 'ID ' . $t->id_especialidad;
        echo "ID: {$t->id_turno}, Dia: {$t->id_dia}, Medico: $medico, Esp: $esp\n";
    }
}
