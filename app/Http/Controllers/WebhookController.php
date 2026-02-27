<?php

namespace App\Http\Controllers;

use App\Models\DetalleError;
use App\Models\Mensajes;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function handleWebhook(Request $request)
    {
        try {
            $token = config('app.whatsapp_token_webhook', env('WHATSAPP_TOKEN_WEBHOOK'));
            $query = $request->query();

            $mode = $query['hub_mode'] ?? null;
            $palabraReto = $query['hub_challenge'] ?? null;
            $tokenVerificacion = $query['hub_verify_token'] ?? null;

            if ($mode && $tokenVerificacion) {
                if ($mode === 'subscribe' && $token == $tokenVerificacion) {
                    return response($palabraReto, 200)->header('Content-Type', 'text/plain');
                }
            }

            throw new Exception("Peticion invalida");
        } catch (Exception $e) {
            return response()->json([
                'estado' => 'error',
                'mensaje' => $e->getMessage(),
            ], 500);
        }
    }

    public function acctionWebhook(Request $request)
    {
        try {
            $bodyContent = json_decode($request->getContent(), true);
            $datos = $bodyContent['entry'][0]['changes'][0]['value'] ?? [];

            if (isset($datos['messages'][0])) {
                $messageData = $datos['messages'][0];
                $metadata = $datos['metadata'];

                $tipo = $messageData['type'] ?? 'text';
                $para = $messageData['from'] ?? 'unknown_sender'; // Teléfono del usuario
                $idMensaje = $messageData['id'] ?? 'unknown_id_' . time();
                $de = $metadata['phone_number_id'] ?? 'unknown_recipient'; // ID de tu número Meta

                $estado = 'received';
                $id_referencia = $messageData['context']['id'] ?? null;

                $mensaje = '';
                $header = 'N/A';
                $tipo_header = 'N/A';
                $isFlowResponse = false;

                // Procesar según el tipo
                if ($tipo == 'text') {
                    $mensaje = $messageData['text']['body'] ?? '';
                } elseif ($tipo == 'image') {
                    $tipo = 'imagen';
                    $idImagen = $messageData['image']['id'] ?? null;
                    $mensaje = $messageData['image']['caption'] ?? 'N/A';
                    if ($idImagen) {
                        $header = $idImagen;
                        $tipo_header = 'IMAGE';
                    }
                } elseif ($tipo == 'video') {
                    $tipo = 'video';
                    $mensaje = $messageData['video']['caption'] ?? 'N/A';
                    $idVideo = $messageData['video']['id'] ?? null;
                    if ($idVideo) {
                        $header = $idVideo;
                        $tipo_header = 'VIDEO';
                    } else {
                        $header = 'N/A';
                        $tipo_header = 'N/A';
                    }
                } elseif ($tipo == 'document') {
                    $tipo = 'documento';
                    $mensaje = $messageData['document']['caption'] ?? 'N/A';
                    $header = $messageData['document']['id'] ?? 'N/A';
                    $tipo_header = 'DOCUMENT';
                } elseif ($tipo == 'audio') {
                    $tipo = 'audio';
                    $mensaje = $messageData['audio']['id'] ?? 'N/A';
                    $header = 'N/A';
                    $tipo_header = 'N/A';
                } elseif ($tipo == 'interactive') {
                    $interactiveData = $messageData['interactive'];

                    if (isset($interactiveData['button_reply'])) {
                        $mensaje = $interactiveData['button_reply']['title'] ?? 'error';
                        $header = 'N/A';
                        $tipo_header = 'N/A';
                    } elseif (isset($interactiveData['list_reply'])) {
                        // AQUÍ CAPTURAMOS LA RESPUESTA DE LA LISTA DE MÉDICOS
                        $title = $interactiveData['list_reply']['title'] ?? 'error';
                        $idOpcion = $interactiveData['list_reply']['id'] ?? 'error';

                        // Validamos si la opción seleccionada corresponde a un Médico (Prefijo: MEDICO_)
                        if (strpos($idOpcion, 'MEDICO_') === 0) {
                            $idMedico = str_replace('MEDICO_', '', $idOpcion);
                            // Le inyectamos una nota oculta al LLM para que sepa exactamente qué ID tiene el médico seleccionado
                            $mensaje = "He seleccionado al médico: $title. [Nota interna para BotSalud: el id_medico seleccionado es $idMedico. Proceder con el flujo para consultar turnos o agendar.]";
                        } else {
                            $mensaje = "He seleccionado la opción: " . $title;
                        }

                        $header = 'N/A';
                        $tipo_header = 'N/A';
                    } elseif (isset($interactiveData['nfm_reply'])) {
                        // Respuesta de un Flow
                        $nfmReply = $interactiveData['nfm_reply'];
                        $responseJson = json_decode($nfmReply['response_json'], true);
                        $mensaje = $nfmReply['body'] ?? 'Formulario enviado';
                        $header = 'FLOW';
                        $tipo_header = 'FLOW';
                        $isFlowResponse = true;

                        Log::info("Webhook nfm_reply recibido:", $responseJson);

                        $identificacion = $responseJson['screen_0_Identificacion_3'] ?? ($responseJson['identificacion'] ?? null);
                        $nombres = $responseJson['screen_0_Nombre_0'] ?? ($responseJson['nombres'] ?? null);
                        $apellidos = $responseJson['screen_0_Apellidos_1'] ?? ($responseJson['apellido'] ?? null);
                        $rut = $responseJson['screen_0_Rut_4'] ?? '';
                        $correo = $responseJson['screen_0_Email_2'] ?? ($responseJson['correo'] ?? null);

                        if ($identificacion && $nombres && $apellidos) {
                            try {
                                $paciente = \App\Models\Pacientes::updateOrCreate(
                                    ['identificacion' => $identificacion],
                                    [
                                        'nombres' => strtoupper($nombres),
                                        'apellidos' => strtoupper($apellidos),
                                        'rut' => $rut,
                                        'estado' => \App\Models\Pacientes::ACTIVO,
                                        'email' => $correo,
                                        'telefono' => $para,
                                        'tipo_documento' => '1',
                                    ]
                                );
                                Log::info("Paciente registrado/actualizado desde Flow: ID $identificacion. Correo: $correo");
                                // Mensaje simulado para que el Bot sepa que ya se registró
                                $mensaje = "He completado el registro exitosamente. Mi identificación es $identificacion.";
                            } catch (Exception $e) {
                                Log::error("Error guardando paciente desde Flow: " . $e->getMessage());
                                $mensaje = "Hubo un error guardando mis datos del formulario.";
                            }
                        } else {
                            Log::warning("Datos incompletos en Flow: ", $responseJson);
                            $mensaje = "Envié el formulario pero faltaron datos.";
                        }
                    }
                } else {
                    Log::info("Tipo de mensaje no manejado recibido: {$tipo}");
                    $mensaje = '[' . ucfirst($tipo) . ' recibido]';
                }

                $agente = 1;
                $mensajeParaTablaGeneral = $isFlowResponse ? "[Respuesta de Flow recibida]" : $mensaje;

                Mensajes::updateOrCreate(
                    ["wamid" => $idMensaje],
                    [
                        "tipo" => $tipo,
                        "de" => $para,
                        "para" => $de,
                        "mensaje" => $mensajeParaTablaGeneral,
                        "header" => $header,
                        "tipo_header" => $tipo_header,
                        "estado" => $estado,
                        "fecha_envio" => Carbon::createFromTimestamp($messageData['timestamp'] ?? time()),
                        "id_agente" => $agente,
                        'id_referencia' => $id_referencia,
                    ]
                );

                // REDIRECCIÓN AL CHATBOT
                // Pasamos el mensaje (que ahora contiene el id_medico interno si era una lista de medicos)
                if ($estado == 'received') {
                    $chatbotController = new ChatBotController();
                    $chatbotController->chatbot($para, $mensaje);
                }
            }

            if (array_key_exists('statuses', $datos)) {
                $tipo = 'text';
                $para = $datos['statuses'][0]['recipient_id'] ?? '111';
                $de = $datos['metadata']['display_phone_number'] ?? 'unknown';
                $mensaje = $datos['entry'][0]['changes'][0]['field'] ?? 'estado_update';
                $agente = 1;
                $idMensaje = $datos['statuses'][0]['id'] ?? 'estado_id';
                $estado = $datos['statuses'][0]['status'] ?? 'sent';

                $validarMensajeEnviado = Mensajes::firstWhere('wamid', $idMensaje);
                if ($validarMensajeEnviado) {
                    $validarMensajeEnviado->update(["estado" => $estado]);
                } else {
                    $validarMensajeEnviado = Mensajes::updateOrCreate([
                        'wamid' => $idMensaje
                    ], [
                        "tipo" => $tipo,
                        "de" => $de,
                        "para" => $para,
                        "mensaje" => $mensaje,
                        "estado" => $estado,
                        "fecha_envio" => Carbon::now(),
                    ]);
                }

                if ($estado == 'failed') {
                    DetalleError::create([
                        'wamid' => $datos['statuses'][0]['id'],
                        'code' => $datos['statuses'][0]['errors'][0]['code'] ?? 'N/A',
                        'title' => $datos['statuses'][0]['errors'][0]['title'] ?? 'N/A',
                        'message' => $datos['statuses'][0]['errors'][0]['message'] ?? 'N/A',
                        'details' => $datos['statuses'][0]['errors'][0]['error_data']['details'] ?? 'N/A',
                    ]);
                }
            }

            return response()->json(['estado' => 'ok']);
        } catch (Exception $e) {
            Log::error("Excepción en WebhookController@acctionWebhook: " . $e->getMessage());
            return response()->json([
                'estado' => 'error',
                'mensaje' => $e->getMessage(),
            ], 500);
        }
    }
}
