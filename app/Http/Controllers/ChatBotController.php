<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ChatBotController extends Controller
{

    private const INTENT_AGENDAR_CITA = 'AGENDAR_CITA';

    public function chatbot(Request $request)
    {
        // Lógica para manejar la conversación del chatbot

    }


    public function identificarIntecionIa($mensaje) {
        $apiKey = config('app.gemini_api_key', env('GOOGLE_AI_API_KEY'));
        $apiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash-latest:generateContent?key=' . $apiKey;

        $prompt = "
            Tu tarea es clasificar el mensaje que te envien en alguna de las siguientes intenciones. Ignora los saludos que te envien y enfocate en identificar la intencion.
        ";
    }
}
