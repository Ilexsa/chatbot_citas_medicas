# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
# Start all dev services (Laravel server + queue + logs + Vite)
composer run dev

# Run tests
composer test
php artisan test --filter NombreTest   # single test

# Linting / formatting (Laravel Pint)
vendor/bin/pint

# Database
php artisan migrate
php artisan migrate:rollback
php artisan db:seed
```

## Required `.env` Variables

```
# WhatsApp Cloud API (Meta)
WHATSAPP_VERSION=v18.0
WHATSAPP_PHONE_NUMBER_ID=
WHATSAPP_TOKEN=
WHATSAPP_TOKEN_WEBHOOK=

# Gemini AI
GOOGLE_AI_API_KEY=

# Google Calendar (Service Account, sin google/apiclient)
GOOGLE_SERVICE_ACCOUNT_JSON=    # ruta relativa al JSON de credenciales
GOOGLE_CALENDAR_ID=

# Costos Gemini (USD por millón de tokens, opcionales)
GEMINI_PRICE_INPUT_PER_1M=0.15
GEMINI_PRICE_OUTPUT_PER_1M=0.60
```

## Architecture

### Request Lifecycle

```
POST /api/webhook
  └── WebhookController::acctionWebhook()
        ├── Parsea tipo de mensaje (text, interactive list_reply, nfm_reply/Flow)
        ├── Persiste en tabla `mensajes` (updateOrCreate por wamid)
        ├── Si es list_reply con prefijo MEDICO_: inyecta id_medico como nota interna al LLM
        ├── Si es nfm_reply (Flow registro): crea/actualiza Paciente directo, sin pasar por IA
        └── ChatBotController::chatbot($telefono, $mensaje)
              ├── Carga últimos 10 mensajes como memoria conversacional
              ├── Detecta paciente por teléfono (últimos 10 dígitos, ignora código de país)
              ├── Construye System Prompt dinámico (modo anónimo vs. paciente validado)
              ├── Bucle IA máx. 5 iteraciones: Gemini decide texto vs. tool call
              ├── executeTool() enruta a métodos tool*() de negocio
              ├── Acumula tokens (usageMetadata) → persiste en `registros_uso` al final
              └── Despacha respuesta a Meta Graph API (texto, lista interactiva, o Flow template)
```

### Function Calling Tools (ChatBotController)

| Tool | Método | Descripción |
|---|---|---|
| `validar_paciente` | `toolValidarPaciente` | Busca paciente ACTIVO; vincula teléfono si falta (Silent Login) |
| `registrar_paciente` | `toolRegistrarPaciente` | Upsert: reactiva si existe, crea si no |
| `consultar_medicos` | `toolConsultarMedicos` | Búsqueda ILIKE en nombres/apellidos y especialidad (stop-words removidas) |
| `consultar_turnos` | `toolConsultarTurnos` | Ventana 30 días, slots de 30 min, algoritmo de colisión con consultas existentes |
| `agendar_cita` | `toolAgendarCita` | Valida turno oficial + re-ejecuta colisión → inserta Consulta AGENDADA → sincroniza Google Calendar |
| `cancelar_cita` | `toolCancelarCita` | Soft-delete a estado CANCELADA → elimina evento de Google Calendar |
| `consultar_mis_citas` | `toolConsultarMisCitas` | Citas futuras del paciente (id_consulta oculto al usuario) |
| `enviar_lista_medicos` | `toolEnviarListaMedicos` | Envia WhatsApp interactive list; rows con ID `MEDICO_{id}` encriptado |
| `enviar_formulario_registro` | `toolEnviarFormularioRegistro` | Envía Flow template `flujo_registro_de_datos` con token `REGISTRO_{identificacion}` |

### Key Design Decisions

- **IDs nunca expuestos al usuario:** `id_medico`, `id_consulta` permanecen en el contexto del LLM, nunca en texto visible.
- **Turnos nocturnos:** Si `hora_fin < hora_ini`, se suma un día a la fecha fin para calcular slots correctamente.
- **Google Calendar sin SDK:** `GoogleCalendarService` implementa JWT RS256 + intercambio OAuth2 con `openssl` nativo y `Http` facade. El Access Token se cachea 3500 segundos.
- **Tolerancia a fallos de Calendar:** Si Google Calendar falla al agendar/cancelar, la cita ya está en BD y el error solo se loguea; el usuario recibe respuesta normal.
- **Graceful degradation IA:** Si las 5 iteraciones se agotan sin respuesta final, se envía mensaje genérico de error.

### Database Models / Tables

- `mensajes` — log de todos los mensajes (entrantes y salientes), campo `id_agente=1` para bot
- `pacientes` — `identificacion` unique, `estado` (ACTIVO/INACTIVO), `telefono` nullable
- `medicos` + `especialidades` + `turnos` — catálogo médico; `turnos.id_dia` usa 1=Lun…7=Dom
- `consultas` — citas médicas; estados: `AGENDADA`, `PENDIENTE`, `CANCELADA`; columna `id_evento_calendar` (nullable) para Google Calendar
- `registros_uso` — tokens de Gemini por conversación (entrada, salida, iteraciones, fecha)
- `detalle_errores` — errores de entrega de WhatsApp (estado `failed`)

### Reporting

`GET /api/reporte-costos?desde=YYYY-MM-DD&hasta=YYYY-MM-DD&trm=4200`

Devuelve costos estimados de Gemini (tokens reales de `registros_uso`) y WhatsApp Cloud API (ventanas de servicio únicas + templates), con `sugerencia_cotizacion` = costo total × 3.
