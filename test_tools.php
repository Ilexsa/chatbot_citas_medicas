<?php

use App\Http\Controllers\ChatBotController;
use App\Models\Consulta;
use App\Models\Medicos;
use App\Models\Turnos;
use Carbon\Carbon;

echo "--- Iniciando Pruebas de Herramientas ---\n";

// 1. Preparar Datos
$medico = Medicos::first();
if (!$medico) {
    die("No hay médicos en BD. Ejecuta el seeder.\n");
}
echo "Medico encontrado: {$medico->nombres} {$medico->apellidos} (ID: {$medico->id_medico})\n";

// Crear Turno para mañana si no existe
$fechaManana = Carbon::now()->addDay();
$diaDb = $fechaManana->dayOfWeek + 1;
$turno = Turnos::updateOrCreate(
    ['id_medico' => $medico->id_medico, 'id_dia' => $diaDb],
    [
        'hora_ini' => '08:00:00',
        'hora_fin' => '10:00:00',
        'id_consultorio' => 1,
        'id_especialidad' => $medico->id_especialidad,
        'estado' => 'A',
        'turno' => 1
    ]
);
echo "Turno asegurado para {$fechaManana->format('Y-m-d')} (Dia: $diaDb): 08:00 - 10:00\n";

// Instanciar Controller (usaremos reflection o public access si hacemos los metodos publicos, 
// o simplemente copiamos la logica, pero mejor hacerlos publicos temporalmente o usar Reflection)
// Para el test, vamos a usar ReflectionMethod para acceder a los metodos privados.

$controller = new ChatBotController();

function callPrivate($obj, $method, $args)
{
    $reflection = new ReflectionMethod($obj, $method);
    $reflection->setAccessible(true);
    return $reflection->invokeArgs($obj, $args);
}

// 2. Test Disponibilidad
echo "\n[Test] Consultar Disponibilidad:\n";
$result = callPrivate($controller, 'toolConsultarDisponibilidad', [$medico->id_medico, $fechaManana->format('Y-m-d')]);
echo "Resultado: $result\n";

// 3. Test Agendar
echo "\n[Test] Agendar Cita a las 08:30:\n";
$pacienteId = 'TEST_USER_999';
$resAgendar = callPrivate($controller, 'toolAgendarCita', [$medico->id_medico, $fechaManana->format('Y-m-d'), '08:30:00', $pacienteId]);
echo "Resultado Agendar: $resAgendar\n";

// 4. Test Disponibilidad Post-Agenda (08:30 debe desaparecer)
echo "\n[Test] Consultar Disponibilidad (Post-Agenda):\n";
$result2 = callPrivate($controller, 'toolConsultarDisponibilidad', [$medico->id_medico, $fechaManana->format('Y-m-d')]);
echo "Resultado: $result2\n";

// 5. Test Consultar Mis Citas
echo "\n[Test] Consultar Mis Citas:\n";
$resMisCitas = callPrivate($controller, 'toolConsultarMisCitas', [$pacienteId]);
echo "Resultado: $resMisCitas\n";

// 6. Test Cancelar Cita
// Extraer ID de la cita creada
$cita = Consulta::where('Id_Paciente', $pacienteId)->where('fecha', $fechaManana->format('Y-m-d'))->where('hora', '08:30:00')->first();
if ($cita) {
    echo "\n[Test] Cancelar Cita ID {$cita->id_consulta}:\n";
    $resCancel = callPrivate($controller, 'toolCancelarCita', [$cita->id_consulta]);
    echo "Resultado: $resCancel\n";

    // Verificar estado
    $cita->refresh();
    echo "Estado actual cita: " . ($cita->estado == Consulta::CANCELADA ? 'CANCELADA' : $cita->estado) . "\n";
} else {
    echo "Error: No se encontró la cita agendada.\n";
}

echo "\n--- Fin Pruebas ---\n";
