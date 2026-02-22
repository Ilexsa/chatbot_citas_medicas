<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Netflie\WhatsAppCloudApi\WhatsAppCloudApi;
use App\Models\Mensajes;

class ChatBotController extends Controller
{

    public function chatbot($telefonoUsuario, $mensaje)
    {
        try {
            // Lógica para manejar la conversación del chatbot

            // $intencion = $this->identificarIntecionIa($mensaje); // Ya no es necesario clasificar estrictamente antes si el LLM maneja todo.
            // Pero mantenemos el log para debug
            // Log::info('Intencion detectada: ' . $intencion);

            // Enrutamos todo a responderSaludo (que ahora es el agente principal)
            $respuesta = $this->responderSaludo($telefonoUsuario, $mensaje);

            if (!empty($respuesta)) {
                $version = config('app.WHATSAPP_VERSION', env('WHATSAPP_VERSION'));
                $phone_number_id = config('app.WHATSAPP_PHONE_NUMBER_ID', env('WHATSAPP_PHONE_NUMBER_ID'));
                $whatsappToken = config('app.WHATSAPP_TOKEN', env('WHATSAPP_TOKEN'));

                $url = "https://graph.facebook.com/{$version}/{$phone_number_id}/messages";

                $headers = [
                    'Authorization' => 'Bearer ' . $whatsappToken,
                    'Content-Type' => 'application/json',
                ];

                $body = [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $telefonoUsuario,
                    'type' => 'text',
                    'text' => [
                        'preview_url' => false,
                        'body' => $respuesta
                    ]
                ];

                try {
                    $response = Http::withHeaders($headers)->post($url, $body);
                    $responseData = $response->json();
                    // Log::info('Respuesta de WhatsApp', [$responseData]);

                    // Guardar respuesta del Bot
                    if (isset($responseData['messages'][0]['id'])) {
                        Mensajes::create([
                            'wamid' => $responseData['messages'][0]['id'],
                            'de' => $phone_number_id,
                            'para' => $telefonoUsuario,
                            'mensaje' => $respuesta,
                            'tipo' => 'text',
                            'estado' => 'sent',
                            'fecha_envio' => now(),
                            'id_agente' => 1
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::error("Error enviando mensaje WhatsApp: " . $e->getMessage());
                }
            }

            return response()->json([
                'respuesta' => $respuesta
            ]);
        } catch (\Exception $e) {
            Log::error("Excepción general en ChatBotController@chatbot: " . $e->getMessage());
            Log::error("Stack trace general: " . $e->getTraceAsString());
            return response()->json([
                'respuesta' => "Lo siento, tuve un problema técnico. Intenta más tarde."
            ]);
        }
    }

    public function identificarIntecionIa($mensaje)
    {
        // Deprecated or kept for fallback.
        return 'CONSULTA_GENERAL';
    }

    public function responderSaludo($telefonoUsuario, $mensaje)
    {
        $apiKey = config('app.gemini_api_key', env('GOOGLE_AI_API_KEY'));
        $apiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . $apiKey;

        // 1. Recuperar historial
        $historialMensajes = Mensajes::where(function ($q) use ($telefonoUsuario) {
            $q->where('de', $telefonoUsuario)
                ->orWhere('para', $telefonoUsuario);
        })
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get()
            ->reverse();

        $contents = [];
        foreach ($historialMensajes as $msg) {
            $role = ($msg->de == $telefonoUsuario) ? 'user' : 'model';
            $texto = $msg->mensaje ?: '...';
            $contents[] = [
                'role' => $role,
                'parts' => [['text' => $texto]]
            ];
        }

        $ultimoMensaje = $historialMensajes->last();
        if (!$ultimoMensaje || $ultimoMensaje->mensaje !== $mensaje) {
            $contents[] = [
                'role' => 'user',
                'parts' => [['text' => $mensaje]]
            ];
        }

        $tools = [
            [
                'function_declarations' => [
                    [
                        'name' => 'consultar_medicos',
                        'description' => 'Busca información de médicos (nombre, especialidad). Utíl para obtener el ID de un médico.',
                        'parameters' => [
                            'type' => 'OBJECT',
                            'properties' => [
                                'query' => ['type' => 'STRING', 'description' => 'Nombre del médico o especialidad']
                            ],
                            'required' => ['query']
                        ]
                    ],
                    [
                        'name' => 'consultar_turnos',
                        'description' => 'Busca turnos y horarios disponibles. Puede filtrar por fecha, médico específico o especialidad.',
                        'parameters' => [
                            'type' => 'OBJECT',
                            'properties' => [
                                'fecha' => ['type' => 'STRING', 'description' => 'Fecha para buscar turnos YYYY-MM-DD'],
                                'id_medico' => ['type' => 'INTEGER', 'description' => 'ID del médico (Opcional)'],
                                'especialidad' => ['type' => 'STRING', 'description' => 'Nombre de la especialidad (Opcional)']
                            ],
                            'required' => ['fecha']
                        ]
                    ],
                    [
                        'name' => 'agendar_cita',
                        'description' => 'Agenda una cita médica. Requiere ID del médico y fecha/hora validada previamente.',
                        'parameters' => [
                            'type' => 'OBJECT',
                            'properties' => [
                                'id_medico' => ['type' => 'INTEGER', 'description' => 'ID del médico'],
                                'fecha' => ['type' => 'STRING', 'description' => 'Fecha YYYY-MM-DD'],
                                'hora' => ['type' => 'STRING', 'description' => 'Hora HH:mm:ss'],
                                'identificacion_paciente' => ['type' => 'STRING', 'description' => 'Cédula/ID del paciente']
                            ],
                            'required' => ['id_medico', 'fecha', 'hora', 'identificacion_paciente']
                        ]
                    ],
                    [
                        'name' => 'consultar_mis_citas',
                        'description' => 'Consulta las citas futuras de un paciente.',
                        'parameters' => [
                            'type' => 'OBJECT',
                            'properties' => [
                                'identificacion_paciente' => ['type' => 'STRING', 'description' => 'Cédula/ID del paciente']
                            ],
                            'required' => ['identificacion_paciente']
                        ]
                    ],
                    [
                        'name' => 'cancelar_cita',
                        'description' => 'Cancela una cita médica existente.',
                        'parameters' => [
                            'type' => 'OBJECT',
                            'properties' => [
                                'id_consulta' => ['type' => 'INTEGER', 'description' => 'ID de la consulta']
                            ],
                            'required' => ['id_consulta']
                        ]
                    ],
                    [
                        'name' => 'validar_paciente',
                        'description' => 'Verifica si un paciente ya existe en la base de datos usando su identificación.',
                        'parameters' => [
                            'type' => 'OBJECT',
                            'properties' => [
                                'identificacion' => ['type' => 'STRING', 'description' => 'Cédula o ID del paciente a validar']
                            ],
                            'required' => ['identificacion']
                        ]
                    ],
                    [
                        'name' => 'enviar_formulario_registro',
                        'description' => 'Envía un formulario interactivo de WhatsApp (Flow) para que el usuario se registre.',
                        'parameters' => [
                            'type' => 'OBJECT',
                            'properties' => [
                                'identificacion' => ['type' => 'STRING', 'description' => 'Cédula o ID del paciente (opcional, para prellenar)']
                            ],
                            'required' => []
                        ]
                    ]
                ]
            ]
        ];

        $systemMessage = "
            Eres 'BotSalud', asistente de 'Fundasen'.

            REGLAS:
            1. AL INICIO DE LA CONVERSACIÓN: Si el usuario saluda o pide algo por primera vez, TU PRIMERA ACCIÓN DEBE SER pedirle amablemente su número de identificación (cédula) para validar si está registrado. No ofrezcas servicios ni consultes turnos hasta tener su identificación.
            2. Cuando el usuario te dé su identificación, usa INMEDIATAMENTE la herramienta 'validar_paciente'.
            3. SI 'validar_paciente' devuelve PACIENTE_NO_ENCONTRADO: USA la herramienta 'enviar_formulario_registro' para enviarle el formulario de registro. Dile: 'No te encontré registrado. Por favor completa este formulario:'. NO le pidas sus datos por chat (nombres, etc.), usa el formulario.
            4. Solo cuando esté registrado (o validado), procede con su solicitud (consultar turnos, agendar, etc.).
            5. Usa 'consultar_turnos' para ver disponibilidad. Puedes buscar por especialidad (ej: 'cardiologia') o médico.
            6. Siempre confirma fecha y hora disponible ANTES de agendar.
            7. Hoy es: " . now()->format('Y-m-d l') . ".
            8. Si busca 'cardiologo', usa 'consultar_turnos' con especialidad='cardiologia'.
            9. IMPORTANTE INTERNAMENTE: Cuando informes sobre médicos o turnos disponibles, DEBES conocer el ID del médico para usar las herramientas, PERO NUNCA LE MUESTRES EL ID DEL MÉDICO AL USUARIO FINAL.
            10. Para la herramienta 'agendar_cita', usa SIEMPRE el 'id_medico' real obtenido de las consultas anteriores.
            11. NUNCA muestres IDs internos (como el ID del médico o el ID de la consulta) en tus mensajes al usuario. Solo menciona Nombres, Especialidades, Fechas y Horas.
            12. Muestra siempre las horas en formato de 12 horas (AM/PM).
        ";

        Log::info("Hoy es: " . now()->format('Y-m-d l'));

        $payload = [
            'systemInstruction' => ['parts' => [['text' => $systemMessage]]],
            'contents' => $contents,
            'tools' => $tools
        ];



        try {
            $maxIter = 5;
            $iter = 0;

            while ($iter < $maxIter) {
                $response = Http::timeout(45)->withHeaders(['Content-Type' => 'application/json'])->post($apiUrl, $payload);
                $responseData = $response->json();

                // 1. Check if Gemini wants to call a tool
                $functionCall = null;
                $textResponse = null;
                $modelPart = null;

                if (isset($responseData['candidates'][0]['content']['parts'])) {
                    foreach ($responseData['candidates'][0]['content']['parts'] as $part) {
                        if (isset($part['functionCall'])) {
                            $functionCall = $part['functionCall'];
                            $modelPart = $part;
                            break;
                        } elseif (isset($part['text'])) {
                            // Guardar el texto por si no hay functionCall
                            $textResponse = $part['text'];
                        }
                    }
                }

                if ($functionCall) {
                    $functionName = $functionCall['name'];
                    $args = $functionCall['args'];

                    Log::info("Gemini llama a tool ($iter): $functionName", $args);
                    $toolResult = $this->executeTool($functionName, $args, $telefonoUsuario);

                    // Append the model's functionCall to history
                    $payload['contents'][] = [
                        'role' => 'model',
                        'parts' => [$modelPart]
                    ];
                    // Append our function response to history
                    $payload['contents'][] = [
                        'role' => 'function',
                        'parts' => [['functionResponse' => ['name' => $functionName, 'response' => ['result' => $toolResult]]]]
                    ];

                    $iter++;
                    continue; // Make another request to Gemini with the new history
                }

                // 2. Check if Gemini returned text
                if ($textResponse) {
                    return $textResponse;
                }

                // 3. If neither, log error and break
                Log::error("Gemini no devolvió la estructura esperada en iteración $iter. Respuesta cruda: ", $responseData ?? []);
                break;
            }

            if ($iter >= $maxIter) {
                Log::warning("Gemini alcanzó el límite máximo de iteraciones ($maxIter) llamando herramientas en cadena.");
                return "He realizado varias búsquedas pero no logro encontrar disponibilidad exacta. Por favor, intenta especificar otra fecha o médico, o verifica el nombre de la especialidad.";
            }

        } catch (\Exception $e) {
            Log::error("Error en Gemini responderSaludo: " . $e->getMessage());
            Log::error("Stack trace: " . $e->getTraceAsString());
            if (isset($responseData)) {
                Log::error("ResponseData crudo de Gemini: ", $responseData);
            }
        }

        return "Lo siento, tuve un problema técnico. Intenta más tarde.";
    }

    private function executeTool($name, $args, $telefonoUsuario = null)
    {
        try {
            switch ($name) {
                case 'consultar_medicos':
                    return $this->toolConsultarMedicos($args['query'] ?? '');
                case 'consultar_turnos':
                    // Mapeo el parametro antiguo o nuevo
                    return $this->toolConsultarTurnos(
                        $args['fecha'] ?? date('Y-m-d'),
                        $args['id_medico'] ?? null,
                        $args['especialidad'] ?? null
                    );
                case 'consultar_disponibilidad': // Backwards compatibility if needed
                    return $this->toolConsultarTurnos($args['fecha'] ?? '', $args['id_medico'] ?? null);
                case 'agendar_cita':
                    return $this->toolAgendarCita($args['id_medico'] ?? 0, $args['fecha'] ?? '', $args['hora'] ?? '', $args['identificacion_paciente'] ?? '');
                case 'consultar_mis_citas':
                    return $this->toolConsultarMisCitas($args['identificacion_paciente'] ?? '');
                case 'cancelar_cita':
                    return $this->toolCancelarCita($args['id_consulta'] ?? 0);
                case 'registrar_paciente':
                    return $this->toolRegistrarPaciente($args['identificacion'] ?? '', $args['nombres'] ?? '', $args['apellidos'] ?? '');
                case 'validar_paciente':
                    return $this->toolValidarPaciente($args['identificacion'] ?? '');
                case 'enviar_formulario_registro':
                    return $this->toolEnviarFormularioRegistro($telefonoUsuario, $args['identificacion'] ?? '');
                default:
                    Log::warning("executeTool: Función no encontrada: $name");
                    return "Función no encontrada.";
            }
        } catch (\Exception $e) {
            Log::error("Excepción en executeTool ($name): " . $e->getMessage());
            Log::error("Stack trace executeTool: " . $e->getTraceAsString());
            Log::error("Args pasados: ", (array)$args);
            return "Hubo un error al ejecutar la función $name.";
        }
    }

    private function toolConsultarMedicos($query)
    {
        Log::info("ToolConsultarMedicos: Query recibido desde Gemini = '$query'");

        // Limpiar el query de caracteres especiales
        $cleanQuery = trim(preg_replace('/[^a-zA-Z0-9áéíóúÁÉÍÓÚñÑ\s]/u', '', $query));

        // Eliminar títulos comunes que Gemini podría incluir (Dr, Dra, Doctor, Doctora, etc)
        $cleanQuery = preg_replace('/\b(dr|dra|doctor|doctora|medico|médico)\b/i', '', $cleanQuery);
        $cleanQuery = trim(preg_replace('/\s+/', ' ', $cleanQuery)); // Limpiar espacios extra

        Log::info("ToolConsultarMedicos: Clean query = '$cleanQuery'");

        $medicos = \App\Models\Medicos::with('especialidad')
            ->where('estado', \App\Models\Medicos::ACTIVO)
            ->where(function ($q) use ($cleanQuery) {
                // 1. Coincidencia exacta o parcial directa en nombres o apellidos completos
                $q->where('nombres', 'ilike', "%$cleanQuery%")
                    ->orWhere('apellidos', 'ilike', "%$cleanQuery%")
                    ->orWhereRaw("CONCAT(nombres, ' ', apellidos) ilike ?", ["%$cleanQuery%"]);

                // 2. Coincidencia por términos individuales (si tiene más de una palabra)
                $terms = explode(' ', $cleanQuery);
                if (count($terms) > 1) {
                    // Requerir que TODOS los términos significativos estén en el nombre o apellido
                    $q->orWhere(function ($subQ) use ($terms) {
                        foreach ($terms as $term) {
                            if (strlen($term) > 2) { // Ignorar conectores como 'de', 'la'
                                $subQ->where(function ($termQ) use ($term) {
                                    $termQ->where('nombres', 'ilike', "%$term%")
                                          ->orWhere('apellidos', 'ilike', "%$term%");
                                });
                            }
                        }
                    });
                } else {
                    // Si es una sola palabra, buscar coincidencias parciales si es larga
                    foreach ($terms as $term) {
                        if (strlen($term) > 3) {
                            Log::info("ToolConsultarMedicos: Buscando por término = '$term'");
                            $q->orWhere('nombres', 'ilike', "%$term%")
                                ->orWhere('apellidos', 'ilike', "%$term%");
                        }
                    }
                }

                // 3. Coincidencia especialidad
                $q->orWhereHas('especialidad', function ($espQ) use ($cleanQuery) {
                    $espQ->where('nombre_especialidad', 'ilike', "%$cleanQuery%");
                });
            })
            ->take(5)
            ->get();

        Log::info("ToolConsultarMedicos: Query SQL ejecutado = " . \App\Models\Medicos::with('especialidad')->where('estado', \App\Models\Medicos::ACTIVO)->where(function ($q) use ($cleanQuery) { $q->where('nombres', 'ilike', "%$cleanQuery%")->orWhere('apellidos', 'ilike', "%$cleanQuery%")->orWhereRaw("CONCAT(nombres, ' ', apellidos) ilike ?", ["%$cleanQuery%"]); $terms = explode(' ', $cleanQuery); if (count($terms) > 1) { $q->orWhere(function ($subQ) use ($terms) { foreach ($terms as $term) { if (strlen($term) > 2) { $subQ->where(function ($termQ) use ($term) { $termQ->where('nombres', 'ilike', "%$term%")->orWhere('apellidos', 'ilike', "%$term%"); }); } } }); } else { foreach ($terms as $term) { if (strlen($term) > 3) { $q->orWhere('nombres', 'ilike', "%$term%")->orWhere('apellidos', 'ilike', "%$term%"); } } } $q->orWhereHas('especialidad', function ($espQ) use ($cleanQuery) { $espQ->where('nombre_especialidad', 'ilike', "%$cleanQuery%"); }); })->take(5)->toSql());
        Log::info("ToolConsultarMedicos: Se encontraron " . $medicos->count() . " coincidencias.");

        if ($medicos->isEmpty()) {
            Log::info("ToolConsultarMedicos: No se encontraron médicos con query: '$cleanQuery'");
            return "No encontré médicos con ese criterio.";
        }

        return $medicos->map(function ($m) {
            Log::info(" - Encontrado: ID={$m->id_medico} {$m->nombres} {$m->apellidos}");
            return [
                'id_medico' => $m->id_medico,
                'nombre' => $m->nombres . ' ' . $m->apellidos,
                'especialidad' => $m->especialidad ? $m->especialidad->nombre_especialidad : 'N/A'
            ];
        })->toJson();
    }

    private function toolConsultarTurnos($fecha, $idMedico = null, $especialidad = null)
    {
        Log::info("ToolConsultarTurnos: Buscando desde Fecha=$fecha, Medico=$idMedico, Esp=$especialidad");

        $fechaInicial = \Carbon\Carbon::parse($fecha);
        $maxDiasBusqueda = 30; // Buscar hasta 30 días en el futuro
        $resultadosTotales = [];
        $fechaEncontrada = null;

        for ($i = 0; $i < $maxDiasBusqueda; $i++) {
            $fechaActual = $fechaInicial->copy()->addDays($i);
            $fechaStr = $fechaActual->format('Y-m-d');
            $dayOfWeek = $fechaActual->dayOfWeek;
            $idDia = ($dayOfWeek == 0) ? 7 : $dayOfWeek;

            Log::info("  Buscando en Día $i: Fecha $fechaStr (dow=$dayOfWeek, id_dia=$idDia)");

            $query = \App\Models\Turnos::with(['medico', 'especialidad'])
                ->where('id_dia', $idDia)
                ->where('estado', 'A');

            if ($idMedico) {
                $query->where('id_medico', $idMedico);
            }

            if ($especialidad) {
                $query->whereHas('especialidad', function ($q) use ($especialidad) {
                    $q->where('nombre_especialidad', 'ilike', "%$especialidad%");
                });
            }

            $turnos = $query->get();

            if ($turnos->isEmpty()) {
                continue; // Saltar al siguiente día si no hay turnos base
            }

            $resultadosDia = [];

            foreach ($turnos as $turno) {
                $horaInicioTurno = \Carbon\Carbon::parse($fechaStr . ' ' . $turno->hora_ini->format('H:i:s'));
                $horaFinTurno = \Carbon\Carbon::parse($fechaStr . ' ' . $turno->hora_fin->format('H:i:s'));

                if ($horaFinTurno->lte($horaInicioTurno)) {
                    $horaFinTurno->addDay();
                }

                $medicoNombre = $turno->medico ? ($turno->medico->nombres . ' ' . $turno->medico->apellidos) : 'Medico ' . $turno->id_medico;
                $especialidadNombre = $turno->especialidad ? $turno->especialidad->nombre_especialidad : 'General';

                $slots = [];
                $cursor = $horaInicioTurno->copy();

                while ($cursor->lt($horaFinTurno)) {
                    $slots[] = $cursor->copy();
                    $cursor->addMinutes(30);
                }

                $citas = \App\Models\Consulta::where('id_medico', $turno->id_medico)
                    ->where('fecha', $fechaStr)
                    ->whereIn('estado', [\App\Models\Consulta::AGENDADA, \App\Models\Consulta::PENDIENTE])
                    ->get();

                if ($horaFinTurno->day != $horaInicioTurno->day) {
                    $fechaSiguiente = $horaInicioTurno->copy()->addDay()->format('Y-m-d');
                    $citasNext = \App\Models\Consulta::where('id_medico', $turno->id_medico)
                        ->where('fecha', $fechaSiguiente)
                        ->whereIn('estado', [\App\Models\Consulta::AGENDADA, \App\Models\Consulta::PENDIENTE])
                        ->get();
                    $citas = $citas->merge($citasNext);
                }

                $disponibles = [];
                foreach ($slots as $slotInicio) {
                    if ($slotInicio->lt(now())) {
                        continue;
                    }

                    $slotFin = $slotInicio->copy()->addMinutes(30);
                    $ocupado = false;

                    foreach ($citas as $cita) {
                        $fechaCitaStr = \Carbon\Carbon::parse($cita->fecha)->format('Y-m-d');
                        $citaInicio = \Carbon\Carbon::parse($fechaCitaStr . ' ' . \Carbon\Carbon::parse($cita->hora_ini ?? $cita->hora)->format('H:i:s'));

                        if (!empty($cita->hora_fin)) {
                             $citaFin = \Carbon\Carbon::parse($fechaCitaStr . ' ' . \Carbon\Carbon::parse($cita->hora_fin)->format('H:i:s'));
                        } else {
                             $citaFin = $citaInicio->copy()->addMinutes(30);
                        }

                        if ($citaFin->lte($citaInicio)) {
                             $citaFin = $citaInicio->copy()->addMinutes(30);
                        }

                        if ($slotInicio->lt($citaFin) && $slotFin->gt($citaInicio)) {
                            $ocupado = true;
                            break;
                        }
                    }

                    if (!$ocupado) {
                        $disponibles[] = $slotInicio->format('h:i A');
                    }
                }

                if (!empty($disponibles)) {
                    $resultadosDia[] = [
                        'id_medico' => $turno->id_medico,
                        'medico' => $medicoNombre,
                        'especialidad' => $especialidadNombre,
                        'turnos_libres' => array_values($disponibles)
                    ];
                }
            }

            if (!empty($resultadosDia)) {
                $resultadosTotales = $resultadosDia;
                $fechaEncontrada = $fechaStr;
                break; // Romper el bucle al encontrar el primer día con disponibilidad
            }
        }

        if (empty($resultadosTotales)) {
            Log::info("ToolConsultarTurnos: No se encontró disponibilidad en los próximos $maxDiasBusqueda días.");
            return "No se encontraron turnos disponibles en los próximos $maxDiasBusqueda días para ese criterio.";
        }

        Log::info("ToolConsultarTurnos: Disponibilidad encontrada para la fecha $fechaEncontrada.");
        return json_encode([
            'fecha_disponible' => $fechaEncontrada,
            'disponibilidad' => $resultadosTotales
        ]);
    }

    private function toolAgendarCita($idMedico, $fecha, $hora, $identificacion)
    {
        Log::info("ToolAgendarCita: Iniciando agendamiento. Medico=$idMedico, Fecha=$fecha, Hora=$hora, ID=$identificacion");

        // 1. Validar que el paciente exista
        $paciente = \App\Models\Pacientes::where('identificacion', $identificacion)
            ->where('estado', \App\Models\Pacientes::ACTIVO)
            ->first();

        if (!$paciente) {
            Log::info("ToolAgendarCita: Paciente no encontrado con ID $identificacion. Solicitando registro.");
            return "PACIENTE_NO_ENCONTRADO: El paciente con identificación $identificacion no está registrado en el sistema. Por favor, pide al usuario sus Nombres y Apellidos completos para registrarlo usando la herramienta 'registrar_paciente'.";
        }

        $diaSemana = \Carbon\Carbon::parse($fecha)->dayOfWeek;
        $idDia = ($diaSemana == 0) ? 7 : $diaSemana;

        Log::info("ToolAgendarCita: Verificando turno activo para el día $idDia a las $hora");

        // Buscar turnos del médico ese día (sin filtrar hora por SQL para manejar cruces de medianoche)
        $turnos = \App\Models\Turnos::where('id_medico', $idMedico)
            ->where('id_dia', $idDia)
            ->where('estado', 'A')
            ->get();

        $turno = null;
        $reqTime = \Carbon\Carbon::parse($fecha . ' ' . $hora);

        foreach ($turnos as $t) {
            $start = \Carbon\Carbon::parse($fecha . ' ' . $t->hora_ini->format('H:i:s'));
            $end = \Carbon\Carbon::parse($fecha . ' ' . $t->hora_fin->format('H:i:s'));

            if ($end->lte($start)) {
                $end->addDay();
            }

            // 1. Check normal (Same day match)
            if ($reqTime->gte($start) && $reqTime->lt($end)) {
                $turno = $t;
                break;
            }

            // 2. Check "Late Night" (User asks for 00:00 meaning tonight/tomorrow morning)
            // If requested time < start, maybe it belongs to the next day part of the shift?
            if ($reqTime->lt($start)) {
                $reqTimeNextDay = $reqTime->copy()->addDay();
                if ($reqTimeNextDay->gte($start) && $reqTimeNextDay->lt($end)) {
                    $turno = $t;
                    // Update the date to the next day for the appointment
                    $fecha = $reqTimeNextDay->format('Y-m-d');
                    Log::info("ToolAgendarCita: Ajustando fecha cita a $fecha (madrugada turno anterior)");
                    break;
                }
            }
        }

        if (!$turno) {
            Log::info("ToolAgendarCita: Fallo - El médico $idMedico no tiene turno activo a las $hora el día $idDia.");
            return "No se puede agendar: El médico no tiene turno activo a esa hora el día $idDia.";
        }

        Log::info("ToolAgendarCita: Turno base encontrado. Verificando solapamiento de citas.");

        $horaInicioRequest = \Carbon\Carbon::parse($hora);
        $horaFinRequest = $horaInicioRequest->copy()->addMinutes(30);

        // Validar solapamiento con otras citas
        $existe = \App\Models\Consulta::where('id_medico', $idMedico)
            ->where('fecha', $fecha)
            ->whereIn('estado', [\App\Models\Consulta::AGENDADA, \App\Models\Consulta::PENDIENTE])
            ->get()
            ->filter(function ($cita) use ($fecha, $horaInicioRequest, $horaFinRequest) {
                $fechaCitaStr = \Carbon\Carbon::parse($cita->fecha)->format('Y-m-d');
                $citaInicio = \Carbon\Carbon::parse($fechaCitaStr . ' ' . \Carbon\Carbon::parse($cita->hora_ini ?? $cita->hora)->format('H:i:s'));
                $citaFin = !empty($cita->hora_fin) ? \Carbon\Carbon::parse($fechaCitaStr . ' ' . \Carbon\Carbon::parse($cita->hora_fin)->format('H:i:s')) : $citaInicio->copy()->addMinutes(30);

                // Overlap: A_Start < B_End && A_End > B_Start
                return $horaInicioRequest->lt($citaFin) && $horaFinRequest->gt($citaInicio);
            })
            ->isNotEmpty();

        if ($existe) {
            return "Lo siento, ese horario ya se encuentra ocupado por otra cita.";
        }

        try {
            $horaFormatted = \Carbon\Carbon::parse($hora)->format('H:i');
            $horaFinFormatted = \Carbon\Carbon::parse($hora)->addMinutes(30)->format('H:i');

            $cita = \App\Models\Consulta::create([
                'id_empresa' => 1,
                'id_localidad' => 1,
                'id_medico' => $idMedico,
                'id_especialidad' => $turno->id_especialidad ?? 1,
                'id_consultorio' => $turno->id_consultorio ?? 1,
                'turno' => 1,
                'fecha' => $fecha,
                'hora' => $horaFormatted,
                'hora_ini' => $horaFormatted,
                'hora_fin' => $horaFinFormatted,
                'estado' => \App\Models\Consulta::AGENDADA,
                'id_paciente' => $identificacion, // Model fillable uses lowercase id_paciente
                'fecha_add' => now(),
                'id_usuario_add' => 'BOT_WHATSAPP'
            ]);

            $medico = \App\Models\Medicos::find($idMedico);
            $nombreMedico = $medico ? ($medico->nombres . ' ' . $medico->apellidos) : 'el médico seleccionado';
            $horaAmPm = \Carbon\Carbon::parse($hora)->format('h:i A');

            return "Cita agendada con éxito para el $fecha a las $horaAmPm con $nombreMedico.";
        } catch (\Exception $e) {
            Log::error("Error agendando cita: " . $e->getMessage());
            return "Hubo un error interno. " . $e->getMessage();
        }
    }

    private function toolRegistrarPaciente($identificacion, $nombres, $apellidos)
    {
        Log::info("ToolRegistrarPaciente: Iniciando registro para ID=$identificacion, Nombres=$nombres, Apellidos=$apellidos");

        try {
            // Verificar si ya existe (podría haber sido creado en otra interacción o por otro usuario)
            $pacienteExistente = \App\Models\Pacientes::where('identificacion', $identificacion)->first();

            if ($pacienteExistente) {
                if ($pacienteExistente->estado == \App\Models\Pacientes::ACTIVO) {
                    return "El paciente con identificación $identificacion ya se encuentra registrado y activo.";
                } else {
                    // Si estaba inactivo, lo reactivamos
                    $pacienteExistente->estado = \App\Models\Pacientes::ACTIVO;
                    $pacienteExistente->nombres = strtoupper($nombres);
                    $pacienteExistente->apellidos = strtoupper($apellidos);
                    $pacienteExistente->save();
                    return "El paciente con identificación $identificacion existía pero estaba inactivo. Ha sido reactivado y actualizado exitosamente.";
                }
            }

            // Crear nuevo paciente
            $nuevoPaciente = \App\Models\Pacientes::create([
                'identificacion' => $identificacion,
                'nombres' => strtoupper($nombres),
                'apellidos' => strtoupper($apellidos),
                'rut' => '', // Valor por defecto si es requerido o dejar vacío
                'estado' => \App\Models\Pacientes::ACTIVO,
                'tipo_documento' => '1', // 1 = Cédula (asumiendo valor por defecto)
            ]);

            Log::info("ToolRegistrarPaciente: Paciente registrado exitosamente. ID interno: " . $nuevoPaciente->id_paciente);
            return "Paciente registrado exitosamente en el sistema. Ya puedes proceder a agendar su cita.";

        } catch (\Exception $e) {
            Log::error("Error registrando paciente: " . $e->getMessage());
            return "Hubo un error al intentar registrar al paciente en la base de datos: " . $e->getMessage();
        }
    }

    private function toolValidarPaciente($identificacion)
    {
        Log::info("ToolValidarPaciente: Verificando paciente con ID=$identificacion");

        try {
            $paciente = \App\Models\Pacientes::where('identificacion', $identificacion)
                ->where('estado', \App\Models\Pacientes::ACTIVO)
                ->first();

            if ($paciente) {
                return "PACIENTE_ENCONTRADO: El paciente {$paciente->nombres} {$paciente->apellidos} está registrado. Puedes proceder con su consulta (ej. consultar turnos o agendar).";
            } else {
                return "PACIENTE_NO_ENCONTRADO: El paciente no existe o está inactivo. Por favor, solicítale sus Nombres y Apellidos completos para registrarlo usando la herramienta 'registrar_paciente'.";
            }
        } catch (\Exception $e) {
            Log::error("Error en toolValidarPaciente: " . $e->getMessage());
            return "Error al consultar la base de datos.";
        }
    }

    private function toolEnviarFormularioRegistro($telefonoUsuario, $identificacion)
    {
        Log::info("ToolEnviarFormularioRegistro: Enviando flow para ID=$identificacion al teléfono $telefonoUsuario");

        $version = config('app.WHATSAPP_VERSION', env('WHATSAPP_VERSION', 'v18.0'));
        $phoneNumberId = config('app.WHATSAPP_PHONE_NUMBER_ID', env('WHATSAPP_PHONE_NUMBER_ID'));
        $token = config('app.WHATSAPP_TOKEN', env('WHATSAPP_TOKEN'));
        $flowId = '1604082303973271';

        $url = "https://graph.facebook.com/{$version}/{$phoneNumberId}/messages";

        $payload = [
    'messaging_product' => 'whatsapp',
    'recipient_type' => 'individual',
    'to' => $telefonoUsuario,
    'type' => 'template',
    'template' => [
        'name' => 'flujo_registro_de_datos', // El nombre que nos dio el JSON
        'language' => [
            'code' => 'es_CO' // El idioma que nos dio el JSON
        ],
        'components' => [
            [
                'type' => 'button',
                'sub_type' => 'flow',
                'index' => '0',
                'parameters' => [
                    [
                        'type' => 'action',
                        'action' => [
                            'flow_token' => "REGISTRO_$identificacion"
                            // Nota: En las plantillas NO hace falta enviar el screen o el flow_id,
                            // porque la plantilla ya tiene configurado "navigate_screen": "SIGN_UP" internamente.
                        ]
                    ]
                ]
            ]
        ]
    ]
];

        try {
            $response = Http::withToken($token)->post($url, $payload);

            if ($response->successful()) {
                Log::info("Flow enviado correctamente: " . $response->body());
                return "FORMULARIO_ENVIADO: Se ha enviado el formulario de registro al WhatsApp del usuario. Indícale que por favor presione el botón 'Registrarme', complete los datos y luego te avise por aquí cuando haya terminado.";
            } else {
                Log::error("Error enviando flow: " . $response->body());
                return "Error al enviar el formulario de registro (API WhatsApp): " . $response->body();
            }
        } catch (\Exception $e) {
            Log::error("Excepción enviando flow: " . $e->getMessage());
            return "Error técnico al enviar el formulario.";
        }
    }

    private function toolConsultarMisCitas($identificacion)
    {
        $citas = \App\Models\Consulta::with(['medico', 'especialidad'])
            ->where('id_paciente', $identificacion)
            ->where('estado', \App\Models\Consulta::AGENDADA)
            ->where('fecha', '>=', now()->format('Y-m-d'))
            ->orderBy('fecha')
            ->get();

        if ($citas->isEmpty()) {
            return "No tienes citas agendadas próximamente.";
        }

        $resultado = [];
        foreach ($citas as $cita) {
            $nombreMedico = $cita->medico ? ($cita->medico->nombres . ' ' . $cita->medico->apellidos) : 'No asignado';
            $nombreEspecialidad = $cita->especialidad ? $cita->especialidad->nombre_especialidad : 'General';

            $resultado[] = [
                'id_consulta_interno' => $cita->id_consulta, // Para uso interno del LLM si necesita cancelar
                'fecha' => $cita->fecha,
                'hora' => \Carbon\Carbon::parse($cita->hora)->format('h:i A'),
                'medico' => $nombreMedico,
                'especialidad' => $nombreEspecialidad
            ];
        }
        return json_encode(['citas_agendadas' => $resultado]);
    }

    private function toolCancelarCita($idConsulta)
    {
        $cita = \App\Models\Consulta::find($idConsulta);

        if (!$cita) {
            return "No encontré una cita con ese ID.";
        }

        if ($cita->estado == \App\Models\Consulta::CANCELADA) {
            return "Esta cita ya estaba cancelada.";
        }

        $cita->estado = \App\Models\Consulta::CANCELADA;
        $cita->fecha_del = now();
        $cita->id_usuario_del = 'BOT_WHATSAPP';
        $cita->save();

        return "Cita #$idConsulta cancelada correctamente.";
    }
}
