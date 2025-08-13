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
        // $token = config('app.whatsapp_token_webhook', env('WHATSAPP_TOKEN_WEBHOOK'));
        try {
            $token = config('app.whatsapp_token_webhook', env('WHATSAPP_TOKEN_WEBHOOK'));
            $query = $request->query();

            $mode = $query['hub_mode'];
            $palabraReto = $query['hub_challenge'];
            $tokenVerificacion = $query['hub_verify_token'];

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
        Log::info('Llego el webhook', $request->all());
    }

    public function acctionWebhook(Request $request)
    {
        try {
            $bodyContent = json_decode($request->getContent(), true);
            $datos = $bodyContent['entry'][0]['changes'][0]['value'];
            $valorChat = null;
            if (isset($datos['messages'][0])) { // Usa isset para más seguridad
                $messageData = $datos['messages'][0];
                $metadata = $datos['metadata']; // Asumiendo que metadata está al mismo nivel que messages

                $tipo = $messageData['type'] ?? 'text';
                $para = $messageData['from'] ?? 'unknown_sender';
                $idMensaje = $messageData['id'] ?? 'unknown_id_' . time(); // Asegura un ID único si falta
                $de = $metadata['phone_number_id'] ?? 'unknown_recipient'; // ID de tu número

                // Estado inicial (puede ser actualizado por 'statuses' luego)
                $estado = 'received'; // O 'delivered' si lo prefieres

                // Obtener id de referencia si existe
                if (isset($messageData['context']['id'])) {
                    $id_referencia = $messageData['context']['id'];
                }

                // Variables para guardar en Mensaje
                $mensaje = '';
                $header = 'N/A';
                $tipo_header = 'N/A';
                $isFlowResponse = false; // Flag para saber si es una respuesta de Flow

                // Procesar según el tipo
                if ($tipo == 'text') {
                    $mensaje = $messageData['text']['body'] ?? '';
                } elseif ($tipo == 'image') {
                    $tipo = 'imagen'; // Cambiando tipo para tu BD
                    $idImagen = $messageData['image']['id'] ?? null;
                    $mensaje = $messageData['image']['caption'] ?? 'N/A';
                    if ($idImagen) {
                        $header = $idImagen; // Guarda el ID como header
                        $tipo_header = 'IMAGE';
                        try {
                            // $response1 = $this->whatsapp_cloud_api->downloadMedia($idImagen);
                            // Asegúrate que el directorio existe y tiene permisos
                            // $imgPath = public_path('img/chat/');
                            // if (!is_dir($imgPath)) mkdir($imgPath, 0775, true);
                            // file_put_contents($imgPath . $idImagen . '.jpg', $response1->body());
                        } catch (Exception $e) {
                            Log::error("Error descargando imagen $idImagen: " . $e->getMessage());
                        }
                    }
                } elseif ($tipo == 'video') {
                    $tipo = 'video'; // Consistent type
                    $mensaje = $messageData['video']['caption'] ?? 'N/A';
                    $idVideo = $messageData['video']['id'] ?? null;
                    if ($idVideo) {
                        $header = $idVideo; // Guarda el ID como header
                        $tipo_header = 'VIDEO';
                        try {
                            // $response1 = $this->whatsapp_cloud_api->downloadMedia($idVideo);
                            // $videoPath = public_path('videos/k2/');
                            // if (!is_dir($videoPath)) mkdir($videoPath, 0775, true);
                            // $nombreVideo = 'video_' . $idVideo . '.mp4'; // Usa ID para nombre único
                            // file_put_contents($videoPath . $nombreVideo, $response1->body());
                            // Podrías guardar $nombreVideo en header si prefieres la ruta
                            // $header = 'videos/k2/' . $nombreVideo;
                        } catch (Exception $e) {
                            Log::error("Error descargando video $idVideo: " . $e->getMessage());
                        }
                    } else {
                        $header = 'N/A';
                        $tipo_header = 'N/A';
                    }
                } elseif ($tipo == 'document') {
                    $tipo = 'documento';
                    $mensaje = $messageData['document']['caption'] ?? 'N/A';
                    $header = $messageData['document']['id'] ?? 'N/A'; // Podrías querer descargar el documento aquí también
                    $tipo_header = 'DOCUMENT';
                } elseif ($tipo == 'audio') {
                    $tipo = 'audio';
                    $mensaje = $messageData['audio']['id'] ?? 'N/A'; // Guarda el ID del audio
                    $header = 'N/A'; // Podrías querer descargar el audio aquí también
                    $tipo_header = 'N/A';
                } elseif ($tipo == 'interactive') {
                    $interactiveData = $messageData['interactive'];
                    if (isset($interactiveData['button_reply'])) {
                        $mensaje = $interactiveData['button_reply']['title'] ?? 'error';
                        $valorChat = $interactiveData['button_reply']['id'] ?? 'error';
                        $header = 'N/A';
                        $tipo_header = 'N/A';
                    } elseif (isset($interactiveData['list_reply'])) {
                        $mensaje = $interactiveData['list_reply']['description'] ?? ($interactiveData['list_reply']['title'] ?? 'error');
                        $valorChat = $interactiveData['list_reply']['id'] ?? 'error';
                        $header = 'N/A';
                        $tipo_header = 'N/A';
                    }
                } else {
                    // Otros tipos de mensaje (location, contacts, etc.)
                    Log::info("Tipo de mensaje no manejado recibido: {$tipo}");
                    $mensaje = '[' . ucfirst($tipo) . ' recibido]';
                }

                $agente = 1;

                $mensajeParaTablaGeneral = $isFlowResponse ? "[Respuesta de Flow recibida]" : $mensaje;

                Mensajes::updateOrCreate(
                    ["id_mensaje" => $idMensaje],
                    [
                        "tipo" => $tipo,
                        "de" => $para,    // Quién envía (usuario)
                        "para" => $de,    // Quién recibe (tu número)
                        "mensaje" => $mensajeParaTablaGeneral, // Usa el texto apropiado
                        "header" => $header,
                        "tipo_header" => $tipo_header,
                        "estado" => $estado, // Estado inicial
                        "fecha_envio" => Carbon::createFromTimestamp($messageData['timestamp'] ?? time()), // Usa el timestamp del mensaje
                        "id_agente" => $agente,
                        'id_referencia' => $id_referencia ?? null, // Guarda el ID de referencia
                    ]
                );


                // Lógica de ChatBot (si aplica y no es una respuesta de Flow que ya manejaste)
                if ($estado == 'received') { // O el estado apropiado
                    $chatbotController = new ChatbotController();
                    $chatbotController->chatBot($para, $mensaje, $valorChat);
                }
            }

            if (array_key_exists('statuses', $datos)) {
                $tipo = 'text';
                $para = $datos['statuses'][0]['recipient_id'] ?? '111';
                $de = $datos['metadata']['display_phone_number'];
                $mensaje = $datos['entry'][0]['changes'][0]['field'] ?? 'James';
                $agente = 1;
                $idMensaje = $datos['statuses'][0]['id'] ?? 'Que pasa';
                $estado = $datos['statuses'][0]['status'] ?? 'sent';

                $validarMensajeEnviado = Mensajes::firstWhere('id_mensaje', $idMensaje);
                if ($validarMensajeEnviado) {
                    $actualizarvalidarMensajeEnviado = $validarMensajeEnviado->update(["estado" => $estado]);
                } else {
                    if (isset($datos['entry'][0]['changes'][0]['value']['messages'][0]['text']['body'])) {
                        $mensaje = $datos['entry'][0]['changes'][0]['value']['messages'][0]['text']['body'] ?? 'James';
                    }
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
                        'code' => $datos['statuses'][0]['errors'][0]['code'],
                        'title' => $datos['statuses'][0]['errors'][0]['title'],
                        'message' => $datos['statuses'][0]['errors'][0]['message'],
                        'details' => $datos['statuses'][0]['errors'][0]['error_data']['details'],
                    ]);
                }

                // -------------------------------------------------------------------------------------------


            }
        } catch (Exception $e) {
            return response()->json([
                'estado' => 'error',
                'mensaje' => $e->getMessage(),
            ], 500);
        }
    }
}
