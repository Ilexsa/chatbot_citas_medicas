# Documentación del Chatbot Fundasen

## 1. Visión General
El proyecto consiste en un chatbot para WhatsApp integrado con la API de **Gemini** (Google AI) y **Laravel**. Su objetivo principal es permitir a los pacientes de la clínica "Fundasen" agendar, consultar y cancelar citas médicas mediante lenguaje natural.

## 2. Flujo de Comunicación
1.  **Recepción**: El mensaje de WhatsApp llega al `WebhookController`.
2.  **Procesamiento**: Se guarda el mensaje en la tabla `mensajes` y se invoca al `ChatBotController`.
3.  **Inteligencia Artificial**:
    *   `ChatBotController::responderSaludo` recupera el historial de los últimos 10 mensajes.
    *   Se envía el contexto y las definiciones de **Herramientas (Tools)** a Gemini.
    *   Gemini decide si responder con texto o invocar una herramienta (Ej: `consultar_turnos`).
4.  **Ejecución de Herramientas**: Si Gemini pide una acción, el controlador ejecuta la lógica PHP correspondiente y devuelve el resultado a Gemini para que genere la respuesta final al usuario.

## 3. Lógica de Negocio y Herramientas

### A. Búsqueda de Médicos (`consultar_medicos`)
*   **Propósito**: Encontrar médicos por nombre o especialidad.
*   **Lógica**:
    *   Busca coincidencias en `nombres` O `apellidos`.
    *   Divide la búsqueda por palabras (ej: "Juan Perez" busca "Juan" Y "Perez" por separado).
    *   Busca coincidencias en el nombre de la `Especialidad` relacionada.
    *   Devuelve: ID, Nombre completo y Especialidad.

### B. Consulta de Disponibilidad y Turnos (`consultar_turnos`)
*   **Propósito**: Determinar qué horarios están libres para una fecha, médico o especialidad específica.
*   **Lógica de Días**:
    *   Se recibe una fecha (YYYY-MM-DD).
    *   Se calcula el día de la semana (`dayOfWeek`).
    *   **Mapeo**: Se asume que en la BD `id_dia` sigue el estándar:
        *   1 = Lunes
        *   2 = Martes
        *   ...
        *   6 = Sábado
        *   7 = Domingo (Ajuste realizado para coincidir con ISO-8601 modificado).
*   **Filtrado de Turnos**:
    *   Se consultan los `Turnos` activos (`estado = 'A'`) para ese `id_dia`.
    *   Filtros opcionales: `id_medico` y `nombre_especialidad`.
*   **Cálculo de Slots**:
    *   Para cada turno, se generan intervalos de 30 minutos desde `hora_ini` hasta `hora_fin`.
    *   Se consultan las citas (`Consulta`) existentes para ese médico y fecha con estado `AGENDADA` o `PENDIENTE`.
    *   **Disponibilidad Real**: `Slots del Turno` - `Citas Ocupadas`.
    *   Devuelve: Lista de médicos con sus horarios libres.

### C. Agendamiento de Citas (`agendar_cita`)
*   **Propósito**: Registrar una nueva cita en el sistema.
*   **Validaciones Críticas**:
    1.  **Validación de Turno**: Verifica que el médico tenga un `Turno` activo para el día de la semana de la fecha solicitada y que la hora esté dentro del rango (`hora_ini` <= hora < `hora_fin`).
    2.  **Validación de Disponibilidad**: Verifica que no exista ya una `Consulta` agendada para ese médico, fecha y hora.
*   **Persistencia**:
    *   Crea un registro en la tabla `consulta`.
    *   Utiliza `id_consultorio` y `id_especialidad` obtenidos del Turno válido.
    *   Estados iniciales: `estado` = 'AGENDADA', `turnos` = 1.

### D. Otras Herramientas
*   `consultar_mis_citas`: Busca citas futuras (fecha >= hoy) para una cédula/identificación dada.
*   `cancelar_cita`: Cambia el estado de una consulta a `CANCELADA` por su ID.

## 4. Estructura de Datos Relevante

### Modelos
*   **Medicos**:
    *   Datos personales.
    *   Relación: `belongTo(Especialidad)`.
    *   Estado: `ACTIVO` (1) / `INACTIVO` (0).
*   **Turnos**:
    *   Define el horario base.
    *   Clave compuesta lógica: `id_medico` + `id_dia` (1..7).
    *   Campos: `hora_ini`, `hora_fin`.
*   **Consulta**:
    *   Registro transaccional de citas.
    *   Estados: `AGENDADA`, `ATENDIDA`, `CANCELADA`.
    *   Clave única lógica: `id_medico` + `fecha` + `hora`.

## 5. Consideraciones Técnicas
*   **Logs**: Se utiliza `Log::info` extensivamente en las herramientas para depurar qué está entendiendo la IA y qué devuelve la base de datos (especialmente para el mapeo de días).
*   **Manejo de Errores**: Bloques `try-catch` capturan fallos en la API de Gemini o en la base de datos para evitar que el bot muera silenciosamente.
*   **Timeouts**: Se aumentaron los timeouts de las peticiones HTTP a 45s para dar tiempo a Gemini de procesar herramientas complejas.
