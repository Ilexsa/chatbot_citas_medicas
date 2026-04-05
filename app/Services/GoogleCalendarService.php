<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleCalendarService
{
    private string $calendarId;
    private string $credentialsPath;
    private string $timezone;

    public function __construct()
    {
        $this->calendarId      = env('GOOGLE_CALENDAR_ID', '');
        $this->credentialsPath = base_path(env('GOOGLE_SERVICE_ACCOUNT_JSON', ''));
        $this->timezone        = env('APP_TIMEZONE', 'America/Bogota');
    }

    /**
     * Crea un evento en Google Calendar para la cita agendada.
     * Devuelve el ID del evento o null si falló.
     */
    public function crearEvento(
        string $fecha,
        string $hora,
        string $nombreMedico,
        string $nombrePaciente,
        string $especialidad
    ): ?string {
        try {
            $token = $this->getAccessToken();
            if (!$token) return null;

            $inicio = Carbon::parse("$fecha $hora", $this->timezone);
            $fin    = $inicio->copy()->addMinutes(30);

            $evento = [
                'summary'     => "Cita: $nombrePaciente",
                'description' => "Médico: $nombreMedico\nEspecialidad: $especialidad\nPaciente: $nombrePaciente",
                'start'       => ['dateTime' => $inicio->toIso8601String(), 'timeZone' => $this->timezone],
                'end'         => ['dateTime' => $fin->toIso8601String(),    'timeZone' => $this->timezone],
                'reminders'   => [
                    'useDefault' => false,
                    'overrides'  => [
                        ['method' => 'popup', 'minutes' => 30],
                    ],
                ],
            ];

            $calendarIdEncoded = rawurlencode($this->calendarId);
            $response = Http::withToken($token)
                ->post("https://www.googleapis.com/calendar/v3/calendars/{$calendarIdEncoded}/events", $evento);

            if ($response->successful()) {
                $eventId = $response->json()['id'] ?? null;
                Log::info("GoogleCalendar: evento creado. ID=$eventId para $nombrePaciente el $fecha a las $hora.");
                return $eventId;
            }

            Log::error("GoogleCalendar: fallo al crear evento. Respuesta: " . $response->body());
            return null;

        } catch (\Exception $e) {
            Log::error("GoogleCalendarService::crearEvento excepción: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Elimina un evento de Google Calendar por su ID.
     */
    public function eliminarEvento(string $eventId): bool
    {
        try {
            $token = $this->getAccessToken();
            if (!$token) return false;

            $calendarIdEncoded = rawurlencode($this->calendarId);
            $response = Http::withToken($token)
                ->delete("https://www.googleapis.com/calendar/v3/calendars/{$calendarIdEncoded}/events/$eventId");

            // 204 = eliminado, 410 = ya no existe (ambos son OK)
            if ($response->successful() || $response->status() === 410) {
                Log::info("GoogleCalendar: evento $eventId eliminado correctamente.");
                return true;
            }

            Log::error("GoogleCalendar: fallo al eliminar evento $eventId. Respuesta: " . $response->body());
            return false;

        } catch (\Exception $e) {
            Log::error("GoogleCalendarService::eliminarEvento excepción: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Obtiene (o reutiliza desde caché) el access token de la Service Account.
     */
    private function getAccessToken(): ?string
    {
        $cacheKey = 'google_calendar_access_token';

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        if (empty($this->credentialsPath) || !file_exists($this->credentialsPath)) {
            Log::error("GoogleCalendarService: archivo de credenciales no encontrado en: '{$this->credentialsPath}'.");
            return null;
        }

        $credentials = json_decode(file_get_contents($this->credentialsPath), true);

        if (!$credentials || empty($credentials['private_key']) || empty($credentials['client_email'])) {
            Log::error("GoogleCalendarService: JSON de credenciales inválido o incompleto.");
            return null;
        }

        $now     = time();
        $header  = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = $this->base64UrlEncode(json_encode([
            'iss'   => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/calendar',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));

        $toSign = "$header.$payload";
        openssl_sign($toSign, $signature, $credentials['private_key'], 'SHA256');
        $jwt = "$toSign." . $this->base64UrlEncode($signature);

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]);

        if (!$response->successful()) {
            Log::error("GoogleCalendarService: no se obtuvo access token. " . $response->body());
            return null;
        }

        $token = $response->json()['access_token'] ?? null;
        if ($token) {
            Cache::put($cacheKey, $token, 3500); // ~58 minutos
        }

        return $token;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
