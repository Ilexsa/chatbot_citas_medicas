<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ChatBotController extends Controller
{

    private const INTENT_AGENDAR_CITA = 'AGENDAR_CITA';

    public function chatbot(Request $request)
    {
        // Lógica para manejar la conversación del chatbot

        $mensajeUsuario = $request->input('mensaje');

        $intencion = $this->identificarIntecionIa($mensajeUsuario);

        switch ($intencion) {
            case self::INTENT_AGENDAR_CITA:
                 $respuesta = " Claro, puedo ayudarte a agendar una cita. Primero porfavor confirmame tus datos personales: Nombre completo, fecha de nacimiento y número de contacto.";
                break;

            default:
                $respuesta = "Lo siento, no puedo ayudar con eso.";
                break;
        }

        return response()->json([
            'respuesta' => $respuesta
        ]);
    }


    public function identificarIntecionIa($mensaje) {
        $apiKey = config('app.gemini_api_key', env('GOOGLE_AI_API_KEY'));
        $apiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash-latest:generateContent?key=' . $apiKey;

        $systemMessage = "
            Enfoque:
            Necesito que me ayudes identificando la intencion de cada mensaje debes seleccionar una intencion de las cuales te voy a adjuntar a continuacion

            INTENCIONES: SOLICITAR_CONSULTA_MEDICA, MENU_ANTERIOR, SOLICITAR_CERTIFICADO, CONSULTA_GENERAL, CANCELAR_CONSULTA, SALUDO, AGENDAR_CITA, AGENDAR_CITA_MEDICO

            Debes de responder siempre en un formato JSON de la siguiente manera intencion: intent: {(Intenciondetectada)}

            Contexto:
            Eres un asistente el cual eres encargado de identificar las intenciones de cada mensaje que se te envie.

            Limites:

            -- Nunca debes de responder en un formato diferente al ya indicado anteriomente.
            -- No debes de dar respuestas ni generar una conversacion solo debes identificar la intencion.
            -- Siempre debes de responder en el formato JSON ya indicado.
            -- No debes de inventar intenciones que no esten en la lista proporcionada.
            -- Si no puedes identificar la intencion debes responder con la intencion CONSULTA_GENERAL

        ";

        $userMessage = $mensaje;

        $response = Http::timeout(10)->post($apiUrl, [
            'contents' => [['parts' => [['text' => $userMessage]]]]
        ]);

    }
}
