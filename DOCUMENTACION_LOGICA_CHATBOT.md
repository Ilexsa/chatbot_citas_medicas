# Documentación Técnica Exhaustiva: Asistente Virtual Fundasen (WhatsApp IA)

## 1. Introducción y Propósito del Sistema
El presente documento detalla la arquitectura, flujos de trabajo y lógica de negocio implementada en el `ChatBotController` del proyecto **Fundasen**. Este sistema funciona como un asistente médico automatizado operando sobre la red de **WhatsApp**.

Utiliza **Google Gemini 2.5 Flash** para dotar al bot de Inteligencia Artificial capaz de comprender lenguaje natural, y emplea la técnica de **Function Calling** (Invocación de Herramientas) para interactuar de forma autónoma con la base de datos de la clínica. Su objetivo principal es automatizar el ciclo de vida de las citas médicas (consulta, agendamiento y cancelación) minimizando la intervención humana.

---

## 2. Pila Tecnológica y Dependencias
*   **Framework:** Laravel (PHP).
*   **Base de Datos:** Eloquent ORM (PostgreSQL/MySQL).
*   **IA Engine:** API de Google Generative AI (`gemini-2.5-flash`).
*   **Mensajería:** API Oficial de Meta (WhatsApp Cloud API v18.0+).
*   **Gestión del Tiempo:** Carbon (para manejo de fechas, zonas horarias y slots de tiempo).

---

## 3. Variables de Entorno Requeridas (`.env`)
El sistema depende estrictamente de las siguientes variables para su comunicación externa:
*   `WHATSAPP_VERSION`: Versión de la API de Graph (Ej: `v18.0`).
*   `WHATSAPP_PHONE_NUMBER_ID`: Identificador único del número de Meta.
*   `WHATSAPP_TOKEN`: Token Bearer con permisos de `whatsapp_business_messaging`.
*   `GOOGLE_AI_API_KEY` (o `gemini_api_key`): Credencial de acceso a Google AI Studio.

---

## 4. Arquitectura de Flujo (Core Lifecycle)

El método de entrada `chatbot($telefonoUsuario, $mensaje)` orquesta el siguiente ciclo de vida:

1.  **Recepción:** Recibe el número del paciente y el texto/intención.
2.  **Generación de Contexto (RAG básico):**
    *   Se consultan los **últimos 10 mensajes** (entrantes y salientes) de la tabla `Mensajes` para proveer "memoria" a la IA y permitir conversaciones secuenciales.
    *   Se extraen los últimos 10 dígitos del teléfono para buscar al paciente en la BD evadiendo inconsistencias con códigos de país (ej. `+57`).
3.  **Inyección de Reglas (System Prompting):** Se construye un bloque de instrucciones rígido para la IA, el cual muta si el paciente ya es reconocido o si es su primera vez interactuando (ver sección de Prompts).
4.  **Bucle de Razonamiento AI (Max 5 Iteraciones):**
    *   La IA analiza la entrada y decide: *¿Respondo texto o ejecuto una acción?*
    *   Si elige acción, la API enruta a `executeTool()`.
    *   El resultado de la BD se devuelve a la IA, quien evalúa si necesita ejecutar otra acción (cadena de herramientas) o si ya tiene la respuesta final.
5.  **Despacho a Meta:** Se arma el Payload de WhatsApp (Texto, Lista o Flow) y se despacha a la Graph API.
6.  **Persistencia Transaccional:** El mensaje de respuesta se guarda en `Mensajes` marcando el autor como agente `1` o Bot.

---

## 5. Ingeniería de Prompts y Reglas de Negocio (System Instructions)

El sistema le inyecta a Gemini un set de reglas inquebrantables.

### 5.1. Manejo Dinámico de Identidad
*   **Modo Paciente Desconocido:** La instrucción prioritaria es exigir la Cédula. Gemini tiene prohibido mostrar turnos o agendar sin invocar primero `validar_paciente`. Si la BD dice que no existe, se bloquea el flujo convencional y se invoca `enviar_formulario_registro`.
*   **Modo Paciente Validado:** Gemini es notificado: *"¡El usuario ya está identificado! Cédula: X, Nombres: Y"*. Se le prohíbe volver a pedir la identidad y se le autoriza usar los servicios clínicos.

### 5.2. Reglas de Comportamiento Global
*   **Protección de Identificadores:** NUNCA se muestran IDs primarios de la base de datos al usuario (como el `id_medico` o el `id_consulta`). Estos se guardan en la memoria de contexto de la IA para pasarlos como parámetros a otras funciones.
*   **Restricciones de Formato:** Todas las horas deben ser transformadas por Gemini a formato de 12 horas (AM/PM).
*   **Uso Obligatorio de UI:** Cuando la IA necesita que el usuario elija un médico, DEBE invocar `enviar_lista_medicos` en lugar de listar los nombres en texto plano.

---

## 6. Diccionario de Herramientas (Function Calling)

El método `executeTool` funciona como enrutador hacia los algoritmos de negocio. A continuación, el detalle técnico de cada herramienta:

### A. Gestión de Pacientes
*   `validar_paciente (identificacion)`: 
    *   Busca en la tabla `Pacientes` (`estado = ACTIVO`).
    *   **Side-effect:** Si encuentra al paciente pero no tiene un teléfono asociado en la BD, se lo vincula automáticamente usando el teléfono de WhatsApp actual (*Silent Login*).
*   `registrar_paciente (identificacion, nombres, apellidos)`:
    *   Upsert lógico: Si la identificación ya existe, reactiva el usuario y actualiza nombres; de lo contrario, lo crea. Vincula el teléfono automáticamente.

### B. Búsqueda y Catálogos
*   `consultar_medicos (query)`:
    *   Realiza limpieza de Stop-words (Dr, Dra, Médico) y caracteres especiales.
    *   Hace un split del query por espacios (Ej: "Carlos Perez"). Busca si algún fragmento mayor a 3 letras hace *match* tipo `ILIKE` en `nombres` o `apellidos`.
    *   También busca coincidencias en la tabla relacional `Especialidad`.
    *   Limitado a 5 resultados para no sobrecargar el token-limit de la IA.

### C. Algoritmo de Disponibilidad de Turnos (Deep Dive)
*   **Herramienta:** `consultar_turnos (fecha, id_medico, especialidad)`
*   **Mecánica de Tiempo:**
    *   Define una ventana deslizante de **30 días**. Itera día por día hasta encontrar el primer bloque libre.
    *   Mapea el día de la semana (`Carbon::dayOfWeek`). Si es Domingo (`0`), lo convierte internamente a `7` para empatar con la estructura de la base de datos.
*   **Manejo de Turnos (Matriz Base):** 
    *   Recupera el registro de `Turnos` del médico para ese `id_dia`.
    *   *Edge Case (Turnos nocturnos):* Si `hora_fin` es menor a `hora_ini` (ej. Turno de 10:00 PM a 06:00 AM), suma un día (`addDay()`) a la fecha de fin de turno.
*   **Generación de Slots (Bloques de 30 min):**
    *   Un ciclo `while` crea puntos en el tiempo cada 30 minutos desde el inicio hasta el fin del turno.
*   **Algoritmo de Colisión (Filtro de Citas):**
    *   Trae todas las citas (`Consulta`) agendadas o pendientes para ese día y médico.
    *   Para cada Slot de 30 minutos, verifica matemáticamente si se intersecta con la `hora_ini` y `hora_fin` de alguna consulta existente.
    *   Descarta slots que ya pasaron en tiempo real (`$slotInicio->lt(now())`).
    *   Devuelve una matriz limpia con los turnos 100% libres a la IA.

### D. Agendamiento Transaccional
*   **Herramienta:** `agendar_cita (id_medico, fecha, hora, identificacion)`
*   **Validación Estricta Nivel 2:**
    1.  Verifica existencia del paciente.
    2.  Verifica que el bloque de tiempo pertenezca a un turno oficial de la tabla `Turnos` ese día.
    3.  Ejecuta nuevamente el *Algoritmo de Colisión* visto en la consulta previa para asegurar que en los últimos milisegundos alguien no haya ocupado la hora.
*   **Persistencia:** Inserta el registro en `Consulta` marcando `hora_ini` (hora dada) y `hora_fin` (+30 mins), asignando estado `AGENDADA`.

### E. Post-Atención
*   `consultar_mis_citas (identificacion)`: Recupera citas agendadas `fecha >= hoy`. Devuelve `id_consulta` oculto, y textos formateados de fecha/medico a la IA.
*   `cancelar_cita (id_consulta)`: Soft-delete. Pasa el estado a `CANCELADA` y marca fecha y usuario de eliminación (`BOT_WHATSAPP`).

---

## 7. Interfaces Ricas de Usuario (WhatsApp UI)
El bot no solo envía texto, invoca las APIs visuales de Meta para enriquecer la experiencia (UX):

1.  **Formularios (WhatsApp Flows):** 
    *   A través de `enviar_formulario_registro`, envía un Template preaprobado (`flujo_registro_de_datos`).
    *   Usa un token de flujo dinámico: `REGISTRO_{identificacion}` que permite a la clínica procesar los datos estructurados en un webhook aparte sin contaminar el chat.
2.  **Listas Interactivas (Radio Buttons):**
    *   A través de `enviar_lista_medicos`.
    *   Construye un JSON del tipo `interactive -> list`.
    *   Los `id` de las filas (Rows) se encriptan bajo el formato `MEDICO_{id}`. Así, cuando el usuario toca "Dr. Juan Perez", WhatsApp envía el texto, pero internamente la aplicación recibe el ID para que Gemini sepa exactamente a quién eligió sin pedirle al usuario que escriba códigos complejos.

---

## 8. Estructura de Datos Inferida (Modelos Eloquent)

De acuerdo a las consultas del controlador, el esquema base requiere al menos la siguiente estructura:

*   **`mensajes`**: Guarda logs del chat. `wamid` (ID Meta), `de`, `para`, `mensaje`, `tipo`, `estado`.
*   **`pacientes`**: `identificacion` (PK virtual/Unique), `nombres`, `apellidos`, `telefono`, `estado` (1 = Activo).
*   **`medicos`**: `id_medico` (PK), `nombres`, `apellidos`, `estado`.
*   **`especialidades`**: `id_especialidad` (PK), `nombre_especialidad`.
*   **`turnos`**: `id_medico`, `id_dia` (1=Lun ... 7=Dom), `hora_ini`, `hora_fin`, `estado`, `id_especialidad`, `id_consultorio`.
*   **`consultas`**: `id_consulta` (PK), `id_paciente`, `id_medico`, `fecha`, `hora_ini`, `hora_fin`, `estado` (AGENDADA, PENDIENTE, CANCELADA).

---

## 10. Integración con Google Calendar

### 10.1. Propósito
Cada cita agendada o cancelada desde el chatbot se sincroniza automáticamente con un Google Calendar específico de la clínica, manteniendo la agenda visible para el personal médico en tiempo real.

### 10.2. Mecanismo de Autenticación (Service Account JWT)
*   **Sin dependencias externas:** Se implementa con PHP `openssl` nativo y el `Http` facade de Laravel. No requiere el paquete `google/apiclient`.
*   **Flujo:** Se construye un JWT firmado con RS256 usando la clave privada del archivo de credenciales de la Service Account. Este JWT se intercambia por un Access Token OAuth2 contra `https://oauth2.googleapis.com/token`.
*   **Caché:** El Access Token se almacena en caché de Laravel por 3500 segundos (~58 min) para evitar una re-autenticación en cada mensaje entrante.
*   **Archivo:** `app/Services/GoogleCalendarService.php`.

### 10.3. Variables de Entorno Requeridas
*   `GOOGLE_SERVICE_ACCOUNT_JSON`: Ruta absoluta al archivo JSON de credenciales de la Service Account (ej. `/var/www/credentials/service-account.json`).
*   `GOOGLE_CALENDAR_ID`: ID del calendario destino (ej. `xxxxxxxx@group.calendar.google.com`).

### 10.4. Setup en Google Cloud (pasos manuales)
1.  Crear proyecto en Google Cloud Console → habilitar **Google Calendar API**.
2.  Crear una **Service Account** → descargar su archivo JSON de credenciales.
3.  En Google Calendar → compartir el calendario objetivo con el email de la Service Account (permiso: **"Hacer cambios en eventos"**).

### 10.5. Ciclo de Vida del Evento
*   **Al agendar (`toolAgendarCita`):** Después de insertar exitosamente en la tabla `consulta`, se invoca `GoogleCalendarService::crearEvento()`. El `id` del evento retornado por la API de Google se persiste en la columna `consulta.id_evento_calendar`. Si la llamada a Google falla, la cita ya quedó guardada en la BD y el fallo se registra en `Log::error` sin interrumpir la respuesta al usuario.
*   **Al cancelar (`toolCancelarCita`):** Después del soft-delete en BD, se verifica si la cita tiene `id_evento_calendar`. De ser así, se invoca `GoogleCalendarService::eliminarEvento()`. Un estado HTTP 410 (ya no existe en Google) se trata como éxito para idempotencia.
*   **Estructura del evento:** `summary`: "Cita: {nombre_paciente}", `description`: médico + especialidad, `start`/`end`: fecha y hora de la cita con duración de 30 minutos, zona horaria `America/Bogota` (configurable vía `APP_TIMEZONE`).

### 10.6. Cambio en Base de Datos
*   **Migración:** `2026_04_04_000001_add_calendar_event_to_consulta.php`
*   **Columna agregada:** `consulta.id_evento_calendar` (`string`, nullable).

---

## 11. Tracking de Costos Operativos

### 11.1. Propósito
Registrar el consumo real de tokens de Gemini por conversación y exponer un endpoint que calcula el costo estimado en USD y COP para un período dado. Permite al operador del sistema cotizar el servicio a sus clientes.

### 11.2. Captura de Tokens (usageMetadata)
La API de Gemini retorna un objeto `usageMetadata` en cada respuesta con los campos `promptTokenCount` y `candidatesTokenCount`. Dentro del bucle de razonamiento IA (max 5 iteraciones), estos valores se **acumulan** en `$totalTokensEntrada` y `$totalTokensSalida`. Al obtener la respuesta final de texto (o al alcanzar el límite de iteraciones), se persiste un registro en la tabla `registros_uso`.

### 11.3. Tabla `registros_uso`
*   **Migración:** `2026_04_04_000002_create_registros_uso_table.php`
*   **Modelo:** `app/Models/RegistroUso.php`

| Columna | Tipo | Descripción |
|---|---|---|
| `id` | bigint PK | Auto-incremental |
| `telefono_usuario` | string(30) | Número WhatsApp del paciente |
| `tokens_entrada` | bigint | Sum de `promptTokenCount` de todas las iteraciones de la conversación |
| `tokens_salida` | bigint | Sum de `candidatesTokenCount` |
| `iteraciones_ia` | tinyint | Cantidad de llamadas a Gemini realizadas |
| `fecha` | date | Fecha de la interacción |

### 11.4. Endpoint de Reporte
*   **Ruta:** `GET /api/reporte-costos`
*   **Controlador:** `app/Http/Controllers/ReporteCostosController.php`
*   **Parámetros (query string):**
    *   `desde`: Fecha inicio `YYYY-MM-DD` (default: inicio del mes en curso).
    *   `hasta`: Fecha fin `YYYY-MM-DD` (default: hoy).
    *   `trm`: Tasa de cambio USD→COP (default: 4200).
*   **Secciones de la respuesta JSON:**
    *   `gemini_2_5_flash`: tokens totales, desglose costo input/output en USD, número de conversaciones e iteraciones.
    *   `whatsapp_cloud_api`: ventanas de servicio únicas (agrupadas por teléfono+día), templates enviados, costo estimado por tipo de conversación.
    *   `resumen`: `total_usd`, `total_cop`, costo promedio por conversación, y `sugerencia_cotizacion` con el total multiplicado ×3 como referencia de precio de venta.

### 11.5. Precios Configurables (`.env`)
*   `GEMINI_PRICE_INPUT_PER_1M`: USD por millón de tokens de entrada (default `0.15`).
*   `GEMINI_PRICE_OUTPUT_PER_1M`: USD por millón de tokens de salida (default `0.60`).
*   Los precios de WhatsApp Cloud API (servicio ~$0.0095 y templates ~$0.0315 para Colombia) están definidos como constantes en el controlador y deben verificarse contra Meta Business Manager, ya que varían por país y cambian periódicamente.

---

## 9. Seguridad, Rendimiento y Tolerancia a Fallos
*   **Control de Bucles de IA:** Las IAs generativas pueden quedar atrapadas llamando herramientas cíclicamente (ej. buscar paciente que no existe, intentar agendar, fallar, volver a buscar). Se ha implementado un límite estricto de **$maxIter = 5**. Si supera este número, el ciclo se rompe y se envía un mensaje genérico al usuario (Graceful Degradation).
*   **Timeouts de Red:** Debido a que el controlador espera a que Gemini evalúe e invoque herramientas (a veces secuencialmente), se utiliza `Http::timeout(45)` en la llamada a la API de Google, evitando errores de Gateway (`504 Gateway Timeout`) de Laravel/Nginx.
*   **Try/Catch Logging:** Todas las acciones a base de datos y envíos a Meta están protegidas en bloques `try/catch`. Cualquier error (como falta de una columna o credencial rota) no detiene el servidor, simplemente escribe en `Log::error` y le devuelve a Gemini un texto de error para que se disculpe orgánicamente con el usuario.