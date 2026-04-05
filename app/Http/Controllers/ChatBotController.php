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
            return response()->json([
                'respuesta' => "Lo siento, tuve un problema técnico. Intenta más tarde."
            ]);
        }
    }

    public function identificarIntecionIa($mensaje)
    {
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

        // ==========================================
        // NUEVA LÓGICA: BUSCAR PACIENTE POR TELÉFONO
        // ==========================================
        $pacienteConocido = null;
        try {
            // Usamos los últimos 10 dígitos para evitar problemas con códigos de país (+57, etc)
            $telefonoCorto = substr($telefonoUsuario, -10);
            $pacienteConocido = \App\Models\Pacientes::where('estado', \App\Models\Pacientes::ACTIVO)
                ->where(function ($q) use ($telefonoUsuario, $telefonoCorto) {
                    $q->where('telefono', 'like', "%$telefonoCorto%")
                        ->orWhere('telefono', 'like', "%$telefonoCorto%");
                })->first();
        } catch (\Exception $e) {
            Log::warning("No se pudo buscar paciente por teléfono (posible falta de columna): " . $e->getMessage());
        }

        // Modificamos las instrucciones dependiendo de si conocemos al paciente
        if ($pacienteConocido) {
            $reglaIdentificacion = "
            1. ¡EL USUARIO YA ESTÁ IDENTIFICADO! Su número de WhatsApp está vinculado en el sistema:
               - Identificación (Cédula): {$pacienteConocido->identificacion}
               - Nombres: {$pacienteConocido->nombres} {$pacienteConocido->apellidos}
               REGLA CLAVE: NO LE PIDAS SU IDENTIFICACIÓN. Procede directamente con su solicitud y usa esta identificación para agendar o consultar sus citas.
            2. Omite la herramienta 'validar_paciente' porque ya ha sido validado automáticamente.
            ";
        } else {
            $reglaIdentificacion = "
            1. AL INICIO DE LA CONVERSACIÓN: Si el usuario saluda o pide algo, TU PRIMERA ACCIÓN DEBE SER pedirle amablemente su número de identificación (cédula) para validar si está registrado. No ofrezcas servicios ni consultes turnos hasta tener su identificación.
            2. Cuando el usuario te dé su identificación, usa INMEDIATAMENTE la herramienta 'validar_paciente'.
            3. SI 'validar_paciente' devuelve PACIENTE_NO_ENCONTRADO: USA la herramienta 'enviar_formulario_registro' para enviarle el formulario de registro. NO le pidas sus datos por chat (nombres, etc.), usa el formulario.
            ";
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
                    ],
                    [
                        'name' => 'enviar_lista_medicos',
                        'description' => 'Envía una lista interactiva de médicos a WhatsApp para que el usuario seleccione. Úsala SIEMPRE para mostrar médicos encontrados.',
                        'parameters' => [
                            'type' => 'OBJECT',
                            'properties' => [
                                'header' => ['type' => 'STRING', 'description' => 'Encabezado de la lista (máximo 60 caracteres)'],
                                'body' => ['type' => 'STRING', 'description' => 'Mensaje principal (instrucciones para el usuario)'],
                                'footer' => ['type' => 'STRING', 'description' => 'Pie de página (máximo 60 caracteres)'],
                                'button_text' => ['type' => 'STRING', 'description' => 'Texto del botón (ej: "Ver Médicos", máx 20 caracteres)'],
                                'medicos' => [
                                    'type' => 'ARRAY',
                                    'description' => 'Lista de médicos a mostrar',
                                    'items' => [
                                        'type' => 'OBJECT',
                                        'properties' => [
                                            'id' => ['type' => 'STRING', 'description' => 'ID interno del médico'],
                                            'titulo' => ['type' => 'STRING', 'description' => 'Nombre del médico (máx 24 caracteres)'],
                                            'descripcion' => ['type' => 'STRING', 'description' => 'Especialidad u otra info (máx 72 caracteres)']
                                        ],
                                        'required' => ['id', 'titulo', 'descripcion']
                                    ]
                                ]
                            ],
                            'required' => ['header', 'body', 'footer', 'button_text', 'medicos']
                        ]
                    ]
                ]
            ]
        ];

        $systemMessage = "
            Eres 'BotSalud', asistente de 'Fundasen'.

            REGLAS:
            {$reglaIdentificacion}
            4. Solo cuando esté registrado (o validado), procede con su solicitud (consultar turnos, agendar, etc.).
            5. Usa 'consultar_turnos' para ver disponibilidad. Puedes buscar por especialidad (ej: 'cardiologia') o médico.
            6. Siempre confirma fecha y hora disponible ANTES de agendar.
            7. Hoy es: " . now()->format('Y-m-d l') . ".
            8. Si busca 'cardiologo', usa 'consultar_turnos' con especialidad='cardiologia'.
            9. IMPORTANTE INTERNAMENTE: Cuando informes sobre médicos o turnos disponibles, DEBES conocer el ID del médico para usar las herramientas, PERO NUNCA LE MUESTRES EL ID DEL MÉDICO AL USUARIO FINAL.
            10. Para la herramienta 'agendar_cita', usa SIEMPRE el 'id_medico' real obtenido de las consultas anteriores.
            11. NUNCA muestres IDs internos (como el ID del médico o el ID de la consulta) en tus mensajes al usuario. Solo menciona Nombres, Especialidades, Fechas y Horas.
            12. Muestra siempre las horas en formato de 12 horas (AM/PM).
            13. CUANDO NECESITES MOSTRAR UNA LISTA DE MÉDICOS AL USUARIO: SIEMPRE usa la herramienta 'enviar_lista_medicos'. Genera un header, body, footer y button_text adecuados, y pasa la lista de médicos. NO escribas los nombres de los médicos en tu respuesta de texto.
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
            $totalTokensEntrada = 0;
            $totalTokensSalida  = 0;

            while ($iter < $maxIter) {
                $response = Http::timeout(45)->withHeaders(['Content-Type' => 'application/json'])->post($apiUrl, $payload);
                $responseData = $response->json();

                // Acumular tokens reales de cada llamada a Gemini
                $usage = $responseData['usageMetadata'] ?? [];
                $totalTokensEntrada += $usage['promptTokenCount']     ?? 0;
                $totalTokensSalida  += $usage['candidatesTokenCount'] ?? 0;

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
                            $textResponse = $part['text'];
                        }
                    }
                }

                if ($functionCall) {
                    $functionName = $functionCall['name'];
                    $args = $functionCall['args'];

                    Log::info("Gemini llama a tool ($iter): $functionName", $args);
                    // Pasamos el $telefonoUsuario a executeTool
                    $toolResult = $this->executeTool($functionName, $args, $telefonoUsuario);

                    $payload['contents'][] = [
                        'role' => 'model',
                        'parts' => [$modelPart]
                    ];
                    $payload['contents'][] = [
                        'role' => 'function',
                        'parts' => [['functionResponse' => ['name' => $functionName, 'response' => ['result' => $toolResult]]]]
                    ];

                    $iter++;
                    continue;
                }

                if ($textResponse) {
                    try {
                        \App\Models\RegistroUso::create([
                            'telefono_usuario' => $telefonoUsuario,
                            'tokens_entrada'   => $totalTokensEntrada,
                            'tokens_salida'    => $totalTokensSalida,
                            'iteraciones_ia'   => $iter + 1,
                            'fecha'            => now()->format('Y-m-d'),
                        ]);
                    } catch (\Exception $e) {
                        Log::warning("No se pudo guardar RegistroUso: " . $e->getMessage());
                    }
                    return $textResponse;
                }

                Log::error("Gemini no devolvió la estructura esperada en iteración $iter. Respuesta cruda: ", $responseData ?? []);
                break;
            }

            if ($iter >= $maxIter) {
                Log::warning("Gemini alcanzó el límite máximo de iteraciones ($maxIter) llamando herramientas en cadena.");
                try {
                    \App\Models\RegistroUso::create([
                        'telefono_usuario' => $telefonoUsuario,
                        'tokens_entrada'   => $totalTokensEntrada,
                        'tokens_salida'    => $totalTokensSalida,
                        'iteraciones_ia'   => $iter,
                        'fecha'            => now()->format('Y-m-d'),
                    ]);
                } catch (\Exception $e) {
                    Log::warning("No se pudo guardar RegistroUso (max iter): " . $e->getMessage());
                }
                return "He realizado varias búsquedas pero no logro encontrar disponibilidad exacta. Por favor, intenta especificar otra fecha o médico, o verifica el nombre de la especialidad.";
            }
        } catch (\Exception $e) {
            Log::error("Error en Gemini responderSaludo: " . $e->getMessage());
            Log::error("Stack trace: " . $e->getTraceAsString());
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
                    return $this->toolConsultarTurnos(
                        $args['fecha'] ?? date('Y-m-d'),
                        $args['id_medico'] ?? null,
                        $args['especialidad'] ?? null
                    );
                case 'agendar_cita':
                    return $this->toolAgendarCita($args['id_medico'] ?? 0, $args['fecha'] ?? '', $args['hora'] ?? '', $args['identificacion_paciente'] ?? '');
                case 'consultar_mis_citas':
                    return $this->toolConsultarMisCitas($args['identificacion_paciente'] ?? '');
                case 'cancelar_cita':
                    return $this->toolCancelarCita($args['id_consulta'] ?? 0);
                case 'registrar_paciente':
                    // Enviamos el telefono para registrarlo
                    return $this->toolRegistrarPaciente($args['identificacion'] ?? '', $args['nombres'] ?? '', $args['apellidos'] ?? '', $telefonoUsuario);
                case 'validar_paciente':
                    // Enviamos el telefono para validarlo
                    return $this->toolValidarPaciente($args['identificacion'] ?? '', $telefonoUsuario);
                case 'enviar_formulario_registro':
                    return $this->toolEnviarFormularioRegistro($telefonoUsuario, $args['identificacion'] ?? '');
                case 'enviar_lista_medicos':
                    return $this->toolEnviarListaMedicos($telefonoUsuario, $args);
                default:
                    Log::warning("executeTool: Función no encontrada: $name");
                    return "Función no encontrada.";
            }
        } catch (\Exception $e) {
            Log::error("Excepción en executeTool ($name): " . $e->getMessage());
            return "Hubo un error al ejecutar la función $name.";
        }
    }

    private function toolConsultarMedicos($query)
    {
        // Limpiar el query de caracteres especiales
        $cleanQuery = trim(preg_replace('/[^a-zA-Z0-9áéíóúÁÉÍÓÚñÑ\s]/u', '', $query));
        $cleanQuery = preg_replace('/\b(dr|dra|doctor|doctora|medico|médico)\b/i', '', $cleanQuery);
        $cleanQuery = trim(preg_replace('/\s+/', ' ', $cleanQuery));

        $medicos = \App\Models\Medicos::with('especialidad')
            ->where('estado', \App\Models\Medicos::ACTIVO)
            ->where(function ($q) use ($cleanQuery) {
                $q->where('nombres', 'ilike', "%$cleanQuery%")
                    ->orWhere('apellidos', 'ilike', "%$cleanQuery%")
                    ->orWhereRaw("CONCAT(nombres, ' ', apellidos) ilike ?", ["%$cleanQuery%"]);

                $terms = explode(' ', $cleanQuery);
                if (count($terms) > 1) {
                    $q->orWhere(function ($subQ) use ($terms) {
                        foreach ($terms as $term) {
                            if (strlen($term) > 2) {
                                $subQ->where(function ($termQ) use ($term) {
                                    $termQ->where('nombres', 'ilike', "%$term%")->orWhere('apellidos', 'ilike', "%$term%");
                                });
                            }
                        }
                    });
                } else {
                    foreach ($terms as $term) {
                        if (strlen($term) > 3) {
                            $q->orWhere('nombres', 'ilike', "%$term%")->orWhere('apellidos', 'ilike', "%$term%");
                        }
                    }
                }
                $q->orWhereHas('especialidad', function ($espQ) use ($cleanQuery) {
                    $espQ->where('nombre_especialidad', 'ilike', "%$cleanQuery%");
                });
            })
            ->take(5)
            ->get();

        if ($medicos->isEmpty()) {
            return "No encontré médicos con ese criterio.";
        }

        return $medicos->map(function ($m) {
            return [
                'id_medico' => $m->id_medico,
                'nombre' => $m->nombres . ' ' . $m->apellidos,
                'especialidad' => $m->especialidad ? $m->especialidad->nombre_especialidad : 'N/A'
            ];
        })->toJson();
    }

    private function toolConsultarTurnos($fecha, $idMedico = null, $especialidad = null)
    {
        $fechaInicial = \Carbon\Carbon::parse($fecha);
        $maxDiasBusqueda = 30;
        $resultadosTotales = [];
        $fechaEncontrada = null;

        for ($i = 0; $i < $maxDiasBusqueda; $i++) {
            $fechaActual = $fechaInicial->copy()->addDays($i);
            $fechaStr = $fechaActual->format('Y-m-d');
            $dayOfWeek = $fechaActual->dayOfWeek;
            $idDia = ($dayOfWeek == 0) ? 7 : $dayOfWeek;

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
                continue;
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
                break;
            }
        }

        if (empty($resultadosTotales)) {
            return "No se encontraron turnos disponibles en los próximos $maxDiasBusqueda días para ese criterio.";
        }

        return json_encode([
            'fecha_disponible' => $fechaEncontrada,
            'disponibilidad' => $resultadosTotales
        ]);
    }

    private function toolAgendarCita($idMedico, $fecha, $hora, $identificacion)
    {
        $paciente = \App\Models\Pacientes::where('identificacion', $identificacion)
            ->where('estado', \App\Models\Pacientes::ACTIVO)
            ->first();

        if (!$paciente) {
            return "PACIENTE_NO_ENCONTRADO: El paciente con identificación $identificacion no está registrado en el sistema. Por favor, pide al usuario sus Nombres y Apellidos completos para registrarlo usando la herramienta 'registrar_paciente'.";
        }

        $diaSemana = \Carbon\Carbon::parse($fecha)->dayOfWeek;
        $idDia = ($diaSemana == 0) ? 7 : $diaSemana;

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

            if ($reqTime->gte($start) && $reqTime->lt($end)) {
                $turno = $t;
                break;
            }

            if ($reqTime->lt($start)) {
                $reqTimeNextDay = $reqTime->copy()->addDay();
                if ($reqTimeNextDay->gte($start) && $reqTimeNextDay->lt($end)) {
                    $turno = $t;
                    $fecha = $reqTimeNextDay->format('Y-m-d');
                    break;
                }
            }
        }

        if (!$turno) {
            return "No se puede agendar: El médico no tiene turno activo a esa hora el día $idDia.";
        }

        $horaInicioRequest = \Carbon\Carbon::parse($hora);
        $horaFinRequest = $horaInicioRequest->copy()->addMinutes(30);

        $existe = \App\Models\Consulta::where('id_medico', $idMedico)
            ->where('fecha', $fecha)
            ->whereIn('estado', [\App\Models\Consulta::AGENDADA, \App\Models\Consulta::PENDIENTE])
            ->get()
            ->filter(function ($cita) use ($fecha, $horaInicioRequest, $horaFinRequest) {
                $fechaCitaStr = \Carbon\Carbon::parse($cita->fecha)->format('Y-m-d');
                $citaInicio = \Carbon\Carbon::parse($fechaCitaStr . ' ' . \Carbon\Carbon::parse($cita->hora_ini ?? $cita->hora)->format('H:i:s'));
                $citaFin = !empty($cita->hora_fin) ? \Carbon\Carbon::parse($fechaCitaStr . ' ' . \Carbon\Carbon::parse($cita->hora_fin)->format('H:i:s')) : $citaInicio->copy()->addMinutes(30);

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
                'id_paciente' => $identificacion,
                'fecha_add' => now(),
                'id_usuario_add' => 'BOT_WHATSAPP'
            ]);

            $medico = \App\Models\Medicos::find($idMedico);
            $nombreMedico = $medico ? ($medico->nombres . ' ' . $medico->apellidos) : 'el médico seleccionado';
            $horaAmPm = \Carbon\Carbon::parse($hora)->format('h:i A');

            // Sincronizar con Google Calendar
            try {
                $pacienteObj = \App\Models\Pacientes::where('identificacion', $identificacion)->first();
                $nombrePaciente = $pacienteObj
                    ? ($pacienteObj->nombres . ' ' . $pacienteObj->apellidos)
                    : $identificacion;
                $especialidadNombre = $turno->especialidad
                    ? $turno->especialidad->nombre_especialidad
                    : 'Consulta General';

                $calendarService = new \App\Services\GoogleCalendarService();
                $eventId = $calendarService->crearEvento(
                    $fecha,
                    \Carbon\Carbon::parse($hora)->format('H:i:s'),
                    $nombreMedico,
                    $nombrePaciente,
                    $especialidadNombre
                );

                if ($eventId) {
                    $cita->id_evento_calendar = $eventId;
                    $cita->save();
                }
            } catch (\Exception $e) {
                Log::error("Error sincronizando cita con Google Calendar: " . $e->getMessage());
            }

            return "Cita agendada con éxito para el $fecha a las $horaAmPm con $nombreMedico.";
        } catch (\Exception $e) {
            Log::error("Error agendando cita: " . $e->getMessage());
            return "Hubo un error interno. " . $e->getMessage();
        }
    }

    private function toolRegistrarPaciente($identificacion, $nombres, $apellidos, $telefonoUsuario)
    {
        Log::info("ToolRegistrarPaciente: Iniciando registro para ID=$identificacion");

        try {
            $pacienteExistente = \App\Models\Pacientes::where('identificacion', $identificacion)->first();

            if ($pacienteExistente) {
                $pacienteExistente->estado = \App\Models\Pacientes::ACTIVO;
                $pacienteExistente->nombres = strtoupper($nombres);
                $pacienteExistente->apellidos = strtoupper($apellidos);

                // Actualizar o vincular su teléfono
                try {
                    if (empty($pacienteExistente->telefono)) {
                        $pacienteExistente->telefono = $telefonoUsuario;
                    } elseif (empty($pacienteExistente->telefono)) {
                        $pacienteExistente->telefono = $telefonoUsuario;
                    }
                } catch (\Exception $e) {
                    Log::warning("Columna telefono no hallada en Pacientes.");
                }

                $pacienteExistente->save();
                return "El paciente con identificación $identificacion ya se encuentra registrado y activo.";
            }

            $nuevoPaciente = new \App\Models\Pacientes([
                'identificacion' => $identificacion,
                'nombres' => strtoupper($nombres),
                'apellidos' => strtoupper($apellidos),
                'rut' => '',
                'estado' => \App\Models\Pacientes::ACTIVO,
                'tipo_documento' => '1',
            ]);

            // Vincular su teléfono
            try {
                $nuevoPaciente->telefono = $telefonoUsuario;
            } catch (\Exception $e) {
                Log::warning("Columna telefono no hallada en Pacientes.");
            }

            $nuevoPaciente->save();

            return "Paciente registrado exitosamente en el sistema. Ya puedes proceder a agendar su cita.";
        } catch (\Exception $e) {
            Log::error("Error registrando paciente: " . $e->getMessage());
            return "Hubo un error al intentar registrar al paciente en la base de datos: " . $e->getMessage();
        }
    }

    private function toolValidarPaciente($identificacion, $telefonoUsuario)
    {
        Log::info("ToolValidarPaciente: Verificando ID=$identificacion para el teléfono $telefonoUsuario");

        try {
            $paciente = \App\Models\Pacientes::where('identificacion', $identificacion)
                ->where('estado', \App\Models\Pacientes::ACTIVO)
                ->first();

            if ($paciente) {

                // Si encontramos al paciente pero no tiene registrado su número, lo enlazamos automáticamente
                try {
                    $actualizado = false;
                    if (empty($paciente->telefono)) {
                        $paciente->telefono = $telefonoUsuario;
                        $actualizado = true;
                    } elseif (empty($paciente->telefono)) {
                        $paciente->telefono = $telefonoUsuario;
                        $actualizado = true;
                    }

                    if ($actualizado) {
                        $paciente->save();
                        Log::info("Teléfono $telefonoUsuario vinculado exitosamente al paciente $identificacion");
                    }
                } catch (\Exception $e) {
                    Log::warning("No se pudo vincular el teléfono al paciente (posible falta de columna telefono/telefono). " . $e->getMessage());
                }

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

        $url = "https://graph.facebook.com/{$version}/{$phoneNumberId}/messages";

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $telefonoUsuario,
            'type' => 'template',
            'template' => [
                'name' => 'flujo_registro_de_datos',
                'language' => [
                    'code' => 'es_CO'
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

    private function toolEnviarListaMedicos($telefonoUsuario, $args)
    {
        $version = config('app.WHATSAPP_VERSION', env('WHATSAPP_VERSION', 'v18.0'));
        $phoneNumberId = config('app.WHATSAPP_PHONE_NUMBER_ID', env('WHATSAPP_PHONE_NUMBER_ID'));
        $token = config('app.WHATSAPP_TOKEN', env('WHATSAPP_TOKEN'));

        $url = "https://graph.facebook.com/{$version}/{$phoneNumberId}/messages";

        $header = mb_substr($args['header'] ?? 'Médicos Disponibles', 0, 60);
        $bodyText = mb_substr($args['body'] ?? 'Selecciona un médico de la siguiente lista:', 0, 1024);
        $footer = mb_substr($args['footer'] ?? 'Fundasen', 0, 60);
        $buttonText = mb_substr($args['button_text'] ?? 'Ver Médicos', 0, 20);

        $rows = [];
        foreach ($args['medicos'] ?? [] as $index => $medico) {
            if ($index >= 10) break;

            $idMedico = $medico['id'] ?? (string)$index;
            $titulo = mb_substr($medico['titulo'] ?? 'Médico', 0, 24);
            $descripcion = mb_substr($medico['descripcion'] ?? '', 0, 72);

            $rows[] = [
                'id' => mb_substr('MEDICO_' . $idMedico, 0, 200),
                'title' => $titulo,
                'description' => $descripcion
            ];
        }

        if (empty($rows)) {
            return "Error: No hay médicos para enviar en la lista.";
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $telefonoUsuario,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'list',
                'header' => [
                    'type' => 'text',
                    'text' => $header
                ],
                'body' => [
                    'text' => $bodyText
                ],
                'footer' => [
                    'text' => $footer
                ],
                'action' => [
                    'button' => $buttonText,
                    'sections' => [
                        [
                            'title' => 'Médicos Disponibles',
                            'rows' => $rows
                        ]
                    ]
                ]
            ]
        ];

        try {
            $response = Http::withToken($token)->post($url, $payload);
            $responseData = $response->json();

            if ($response->successful()) {
                if (isset($responseData['messages'][0]['id'])) {
                    Mensajes::create([
                        'wamid' => $responseData['messages'][0]['id'],
                        'de' => $phoneNumberId,
                        'para' => $telefonoUsuario,
                        'mensaje' => $bodyText,
                        'tipo' => 'interactive',
                        'estado' => 'sent',
                        'fecha_envio' => now(),
                        'id_agente' => 1
                    ]);
                }

                return "LISTA_ENVIADA: Se ha enviado la lista interactiva de médicos al usuario. Pídele al usuario que seleccione un médico de la lista que acabas de enviar.";
            } else {
                Log::error("Error enviando lista de médicos: " . $response->body());
                return "Error al enviar la lista de médicos (API WhatsApp): " . $response->body();
            }
        } catch (\Exception $e) {
            Log::error("Excepción enviando lista de médicos: " . $e->getMessage());
            return "Error técnico al enviar la lista.";
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
                'id_consulta_interno' => $cita->id_consulta,
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

        // Eliminar el evento de Google Calendar si existe
        if (!empty($cita->id_evento_calendar)) {
            try {
                $calendarService = new \App\Services\GoogleCalendarService();
                $calendarService->eliminarEvento($cita->id_evento_calendar);
            } catch (\Exception $e) {
                Log::error("Error eliminando evento de Google Calendar (id_consulta=$idConsulta): " . $e->getMessage());
            }
        }

        return "Cita #$idConsulta cancelada correctamente.";
    }
}
