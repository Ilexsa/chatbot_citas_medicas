<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Medicos;

echo "Listando primeros 20 medicos:\n";
$medicos = Medicos::take(20)->get();
foreach ($medicos as $m) {
    echo "ID: {$m->id_medico}, Nombre: {$m->nombres} {$m->apellidos}, Estado: {$m->estado}\n";
}

echo "\nBuscando 'Carlos':\n";
$carlos = Medicos::where('nombres', 'ilike', '%Carlos%')->get();
foreach ($carlos as $m) {
    echo "ID: {$m->id_medico}, Nombre: {$m->nombres} {$m->apellidos}, Estado: {$m->estado}, Esp ID: {$m->id_especialidad}\n";
}
