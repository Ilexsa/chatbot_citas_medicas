<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Pacientes;
use Illuminate\Support\Facades\Schema;

echo "Verificando columnas de Pacientes:\n";
$columns = Schema::getColumnListing('pacientes');
foreach ($columns as $col) {
    echo "- $col\n";
}
