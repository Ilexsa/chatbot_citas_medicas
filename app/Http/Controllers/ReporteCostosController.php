<?php

namespace App\Http\Controllers;

use App\Models\Mensajes;
use App\Models\RegistroUso;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReporteCostosController extends Controller
{
    // -------------------------------------------------------
    // Precios Gemini 2.5 Flash (USD por 1 millón de tokens)
    // Configurable vía .env: GEMINI_PRICE_INPUT_PER_1M / GEMINI_PRICE_OUTPUT_PER_1M
    // -------------------------------------------------------
    const PRECIO_INPUT_DEFAULT  = 0.15;
    const PRECIO_OUTPUT_DEFAULT = 0.60;

    // -------------------------------------------------------
    // WhatsApp Cloud API — Colombia (USD)
    // Conversación de servicio (usuario inicia, ventana 24h): ~$0.0095
    // Mensaje de negocio iniciado con template:             ~$0.0315
    // Fuente: Meta Business pricing (verificar en tu cuenta)
    // -------------------------------------------------------
    const COSTO_CONV_SERVICIO = 0.0095;
    const COSTO_TEMPLATE      = 0.0315;

    /**
     * GET /dashboard/costos — Vista Blade del dashboard de costos.
     */
    public function dashboard(Request $request)
    {
        $desde  = $request->get('desde', Carbon::now()->startOfMonth()->format('Y-m-d'));
        $hasta  = $request->get('hasta', Carbon::now()->format('Y-m-d'));
        $trmCop = (float) $request->get('trm', 4200);

        $data = $this->calcularReporte($desde, $hasta, $trmCop);

        // Datos diarios para la gráfica
        $precioInput  = (float) env('GEMINI_PRICE_INPUT_PER_1M',  self::PRECIO_INPUT_DEFAULT);
        $precioOutput = (float) env('GEMINI_PRICE_OUTPUT_PER_1M', self::PRECIO_OUTPUT_DEFAULT);

        $porDia = RegistroUso::selectRaw('fecha, SUM(tokens_entrada) as tokens_entrada, SUM(tokens_salida) as tokens_salida, COUNT(*) as conversaciones')
            ->whereBetween('fecha', [$desde, $hasta])
            ->groupBy('fecha')
            ->orderBy('fecha')
            ->get()
            ->map(function ($row) use ($precioInput, $precioOutput) {
                $costo = (($row->tokens_entrada / 1_000_000) * $precioInput)
                       + (($row->tokens_salida  / 1_000_000) * $precioOutput);
                return [
                    'fecha'          => $row->fecha->format('d/m'),
                    'conversaciones' => $row->conversaciones,
                    'costo_usd'      => round($costo, 5),
                ];
            });

        // Top 10 usuarios por consumo de tokens
        $topUsuarios = RegistroUso::selectRaw('telefono_usuario, SUM(tokens_entrada + tokens_salida) as total_tokens, COUNT(*) as conversaciones')
            ->whereBetween('fecha', [$desde, $hasta])
            ->groupBy('telefono_usuario')
            ->orderByDesc('total_tokens')
            ->limit(10)
            ->get();

        return view('dashboard.costos', compact('data', 'desde', 'hasta', 'trmCop', 'porDia', 'topUsuarios'));
    }

    /**
     * GET /api/reporte-costos?desde=YYYY-MM-DD&hasta=YYYY-MM-DD&trm=4200
     *
     * Retorna el costo estimado de operar el chatbot en el período indicado.
     * Si no se pasan fechas, asume el mes en curso.
     */
    public function reporte(Request $request)
    {
        $desde  = $request->get('desde', Carbon::now()->startOfMonth()->format('Y-m-d'));
        $hasta  = $request->get('hasta', Carbon::now()->format('Y-m-d'));
        $trmCop = (float) $request->get('trm', 4200);

        $data = $this->calcularReporte($desde, $hasta, $trmCop);

        return response()->json(array_merge(['periodo' => ['desde' => $desde, 'hasta' => $hasta]], $data));
    }

    private function calcularReporte(string $desde, string $hasta, float $trmCop): array
    {
        // ===================================================
        // GEMINI — tokens reales capturados en cada respuesta
        // ===================================================
        $registros = RegistroUso::whereBetween('fecha', [$desde, $hasta])->get();

        $totalTokensEntrada  = $registros->sum('tokens_entrada');
        $totalTokensSalida   = $registros->sum('tokens_salida');
        $totalConversaciones = $registros->count();
        $totalIteracionesIa  = $registros->sum('iteraciones_ia');

        $precioInput  = (float) env('GEMINI_PRICE_INPUT_PER_1M',  self::PRECIO_INPUT_DEFAULT);
        $precioOutput = (float) env('GEMINI_PRICE_OUTPUT_PER_1M', self::PRECIO_OUTPUT_DEFAULT);

        $costoInputUsd  = ($totalTokensEntrada / 1_000_000) * $precioInput;
        $costoOutputUsd = ($totalTokensSalida  / 1_000_000) * $precioOutput;
        $costoGeminiUsd = $costoInputUsd + $costoOutputUsd;

        // ===================================================
        // WHATSAPP — ventanas de conversación + templates
        // ===================================================
        $ventanasServicio = Mensajes::selectRaw("TO_CHAR(fecha_envio, 'YYYY-MM-DD') as dia, de as telefono")
            ->where('id_agente', '!=', 1)
            ->whereBetween('fecha_envio', ["$desde 00:00:00", "$hasta 23:59:59"])
            ->groupBy('dia', 'telefono')
            ->get()
            ->count();

        $templatesEnviados = Mensajes::where('id_agente', 1)
            ->where('tipo', 'template')
            ->whereBetween('fecha_envio', ["$desde 00:00:00", "$hasta 23:59:59"])
            ->count();

        $costoWaServicioUsd  = $ventanasServicio * self::COSTO_CONV_SERVICIO;
        $costoWaTemplatesUsd = $templatesEnviados * self::COSTO_TEMPLATE;
        $costoWhatsAppUsd    = $costoWaServicioUsd + $costoWaTemplatesUsd;

        // ===================================================
        // TOTALES
        // ===================================================
        $totalUsd = $costoGeminiUsd + $costoWhatsAppUsd;

        $promUsdPorConv = $totalConversaciones > 0 ? round($totalUsd / $totalConversaciones, 6) : 0;
        $promCopPorConv = $totalConversaciones > 0 ? round(($totalUsd * $trmCop) / $totalConversaciones) : 0;

        return [
            'gemini_2_5_flash' => [
                'conversaciones_registradas' => $totalConversaciones,
                'total_llamadas_ia'          => $totalIteracionesIa,
                'tokens_entrada'             => $totalTokensEntrada,
                'tokens_salida'              => $totalTokensSalida,
                'costo_input_usd'            => round($costoInputUsd, 4),
                'costo_output_usd'           => round($costoOutputUsd, 4),
                'costo_total_usd'            => round($costoGeminiUsd, 4),
                'precios_configurados'       => [
                    'input_por_1M_tokens_usd'  => $precioInput,
                    'output_por_1M_tokens_usd' => $precioOutput,
                ],
            ],
            'whatsapp_cloud_api' => [
                'ventanas_servicio_24h'  => $ventanasServicio,
                'templates_enviados'     => $templatesEnviados,
                'costo_servicio_usd'     => round($costoWaServicioUsd, 4),
                'costo_templates_usd'    => round($costoWaTemplatesUsd, 4),
                'costo_total_usd'        => round($costoWhatsAppUsd, 4),
                'nota'                   => 'Precios aproximados para Colombia. Verifícalos en Meta Business Manager > Billing.',
            ],
            'resumen' => [
                'total_usd'                           => round($totalUsd, 4),
                'total_cop'                           => (int) round($totalUsd * $trmCop),
                'trm_usada'                           => $trmCop,
                'costo_promedio_por_conversacion_usd' => $promUsdPorConv,
                'costo_promedio_por_conversacion_cop' => $promCopPorConv,
                'sugerencia_cotizacion'               => [
                    'nota'          => 'Agrega al menos 3× el costo para cubrir operación, soporte y ganancia.',
                    'precio_x3_usd' => round($totalUsd * 3, 2),
                    'precio_x3_cop' => (int) round($totalUsd * 3 * $trmCop),
                ],
            ],
        ];
    }
}
