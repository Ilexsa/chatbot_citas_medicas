use App\Http\Controllers\ChatBotController;
use App\Models\Mensajes;

// Limpiar mensajes de prueba anteriores
Mensajes::where('de', '555999')->orWhere('para', '555999')->delete();

echo "Simulando conversación...\n";

// 1. Crear historial
echo "Creando mensaje usuario: 'Hola, soy Juan'\n";
Mensajes::create([
'wamid' => 'test_id_1',
'de' => '555999',
'para' => 'BOT',
'mensaje' => 'Hola, soy Juan',
'tipo' => 'text',
'estado' => 'received',
'fecha_envio' => now()->subMinutes(5),
'id_agente' => 1
]);

echo "Creando mensaje bot: 'Hola Juan, ¿en qué puedo ayudarte?'\n";
Mensajes::create([
'wamid' => 'test_id_2',
'de' => 'BOT', // Asumiendo que mi ID no es 555999
'para' => '555999',
'mensaje' => 'Hola Juan, ¿en qué puedo ayudarte?',
'tipo' => 'text',
'estado' => 'sent',
'fecha_envio' => now()->subMinutes(4),
'id_agente' => 1
]);

// 2. Llamar a responderSaludo con una pregunta que requiere contexto
echo "Preguntando: 'Como me llamo?'\n";
$controller = new ChatBotController();
$response = $controller->responderSaludo('555999', 'Como me llamo?');

echo "Respuesta IA: " . $response . "\n";