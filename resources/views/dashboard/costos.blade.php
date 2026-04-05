@extends('layouts.app')

@section('title', 'Dashboard de Costos')

@push('head')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        @if(!$porDia->isEmpty())
        const labels = @json($porDia->pluck('fecha'));
        const costos = @json($porDia->pluck('costo_usd'));
        const convs  = @json($porDia->pluck('conversaciones'));

        new Chart(document.getElementById('chartDiario'), {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Costo USD',
                        data: costos,
                        backgroundColor: 'rgba(59, 130, 246, 0.15)',
                        borderColor: 'rgba(59, 130, 246, 0.8)',
                        borderWidth: 2,
                        borderRadius: 4,
                        yAxisID: 'y',
                    },
                    {
                        label: 'Conversaciones',
                        data: convs,
                        type: 'line',
                        borderColor: 'rgba(16, 185, 129, 0.8)',
                        backgroundColor: 'transparent',
                        borderWidth: 2,
                        pointRadius: 3,
                        tension: 0.3,
                        yAxisID: 'y1',
                    }
                ]
            },
            options: {
                responsive: true,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'top', labels: { font: { size: 12 } } },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                if (ctx.datasetIndex === 0) return ` Costo: $${ctx.parsed.y.toFixed(5)} USD`;
                                return ` Conversaciones: ${ctx.parsed.y}`;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        type: 'linear',
                        position: 'left',
                        ticks: { font: { size: 11 }, callback: v => '$' + v.toFixed(4) },
                        grid: { color: 'rgba(0,0,0,0.04)' }
                    },
                    y1: {
                        type: 'linear',
                        position: 'right',
                        ticks: { font: { size: 11 } },
                        grid: { drawOnChartArea: false }
                    },
                    x: { ticks: { font: { size: 11 } }, grid: { display: false } }
                }
            }
        });
        @endif
    });
</script>
@endpush

@section('content')

{{-- Header --}}
<div class="mb-8">
    <h1 class="text-2xl font-bold text-gray-900">Dashboard de Costos Operativos</h1>
    <p class="text-gray-500 mt-1 text-sm">Consumo real de Gemini 2.5 Flash + WhatsApp Cloud API</p>
</div>

{{-- Filtros --}}
<form method="GET" action="/dashboard/costos"
      class="bg-white rounded-xl border border-gray-200 p-5 mb-8 flex flex-wrap items-end gap-4">
    <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Desde</label>
        <input type="date" name="desde" value="{{ $desde }}"
               class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
    </div>
    <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Hasta</label>
        <input type="date" name="hasta" value="{{ $hasta }}"
               class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
    </div>
    <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">TRM (USD → COP)</label>
        <input type="number" name="trm" value="{{ $trmCop }}" step="10" min="1"
               class="border border-gray-300 rounded-lg px-3 py-2 text-sm w-32 focus:outline-none focus:ring-2 focus:ring-green-500">
    </div>
    <button type="submit"
            class="bg-green-600 hover:bg-green-700 text-white text-sm font-medium px-5 py-2 rounded-lg transition-colors">
        Aplicar filtros
    </button>
    {{-- Accesos rápidos de período --}}
    @php $hoy = \Carbon\Carbon::now(); @endphp
    <div class="flex gap-2 ml-auto flex-wrap">
        <a href="/dashboard/costos?desde={{ $hoy->copy()->startOfMonth()->format('Y-m-d') }}&hasta={{ $hoy->format('Y-m-d') }}&trm={{ $trmCop }}"
           class="text-xs border border-gray-300 rounded-lg px-3 py-2 hover:bg-gray-50 transition-colors">
            Este mes
        </a>
        <a href="/dashboard/costos?desde={{ $hoy->copy()->subDays(6)->format('Y-m-d') }}&hasta={{ $hoy->format('Y-m-d') }}&trm={{ $trmCop }}"
           class="text-xs border border-gray-300 rounded-lg px-3 py-2 hover:bg-gray-50 transition-colors">
            Últimos 7 días
        </a>
        <a href="/dashboard/costos?desde={{ $hoy->copy()->subDays(29)->format('Y-m-d') }}&hasta={{ $hoy->format('Y-m-d') }}&trm={{ $trmCop }}"
           class="text-xs border border-gray-300 rounded-lg px-3 py-2 hover:bg-gray-50 transition-colors">
            Últimos 30 días
        </a>
    </div>
</form>

{{-- KPI Cards principales --}}
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">

    <div class="bg-white rounded-xl border border-gray-200 p-5">
        <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Costo total</p>
        <p class="text-3xl font-bold text-gray-900 mt-2">${{ number_format($data['resumen']['total_usd'], 4) }}</p>
        <p class="text-sm text-gray-500 mt-1">USD</p>
        <p class="text-sm font-semibold text-gray-700 mt-1">$ {{ number_format($data['resumen']['total_cop']) }} COP</p>
    </div>

    <div class="bg-green-50 rounded-xl border border-green-200 p-5">
        <p class="text-xs font-medium text-green-700 uppercase tracking-wide">Precio sugerido ×3</p>
        <p class="text-3xl font-bold text-green-700 mt-2">${{ number_format($data['resumen']['sugerencia_cotizacion']['precio_x3_usd'], 2) }}</p>
        <p class="text-sm text-green-600 mt-1">USD</p>
        <p class="text-sm font-semibold text-green-800 mt-1">$ {{ number_format($data['resumen']['sugerencia_cotizacion']['precio_x3_cop']) }} COP</p>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 p-5">
        <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Conversaciones</p>
        <p class="text-3xl font-bold text-gray-900 mt-2">{{ number_format($data['gemini_2_5_flash']['conversaciones_registradas']) }}</p>
        <p class="text-sm text-gray-500 mt-1">{{ number_format($data['gemini_2_5_flash']['total_llamadas_ia']) }} llamadas a IA</p>
        <p class="text-sm text-gray-500 mt-1">Prom: ${{ number_format($data['resumen']['costo_promedio_por_conversacion_usd'], 5) }} / conv</p>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 p-5">
        <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Costo prom. / conversación</p>
        <p class="text-3xl font-bold text-gray-900 mt-2">$ {{ number_format($data['resumen']['costo_promedio_por_conversacion_cop']) }}</p>
        <p class="text-sm text-gray-500 mt-1">COP (TRM: ${{ number_format($trmCop) }})</p>
        <p class="text-sm text-gray-500 mt-1">${{ number_format($data['resumen']['costo_promedio_por_conversacion_usd'], 6) }} USD</p>
    </div>

</div>

{{-- Gráfica diaria + Desglose --}}
<div class="grid grid-cols-1 lg:grid-cols-3 gap-5 mb-8">

    <div class="bg-white rounded-xl border border-gray-200 p-5 lg:col-span-2">
        <h2 class="text-sm font-semibold text-gray-700 mb-4">Costo diario Gemini (USD) vs Conversaciones</h2>
        @if($porDia->isEmpty())
            <div class="flex items-center justify-center h-48 text-gray-400 text-sm">
                Sin datos para el período seleccionado
            </div>
        @else
            <canvas id="chartDiario" height="110"></canvas>
        @endif
    </div>

    <div class="bg-white rounded-xl border border-gray-200 p-5">
        <h2 class="text-sm font-semibold text-gray-700 mb-4">Desglose por servicio</h2>
        <div class="space-y-4">
            @php
                $totalUsd = $data['resumen']['total_usd'];
                $pctGemini = $totalUsd > 0 ? ($data['gemini_2_5_flash']['costo_total_usd'] / $totalUsd) * 100 : 0;
                $pctWa     = $totalUsd > 0 ? ($data['whatsapp_cloud_api']['costo_total_usd'] / $totalUsd) * 100 : 0;
            @endphp

            <div>
                <div class="flex justify-between items-center mb-1">
                    <span class="text-sm text-gray-600">Gemini 2.5 Flash</span>
                    <span class="text-sm font-semibold text-gray-900">${{ number_format($data['gemini_2_5_flash']['costo_total_usd'], 4) }}</span>
                </div>
                <div class="w-full bg-gray-100 rounded-full h-2">
                    <div class="bg-blue-500 h-2 rounded-full" style="width: {{ $pctGemini }}%"></div>
                </div>
                <div class="flex justify-between text-xs text-gray-400 mt-1">
                    <span>Input: ${{ $data['gemini_2_5_flash']['costo_input_usd'] }}</span>
                    <span>Output: ${{ $data['gemini_2_5_flash']['costo_output_usd'] }}</span>
                </div>
            </div>

            <div>
                <div class="flex justify-between items-center mb-1">
                    <span class="text-sm text-gray-600">WhatsApp Cloud API</span>
                    <span class="text-sm font-semibold text-gray-900">${{ number_format($data['whatsapp_cloud_api']['costo_total_usd'], 4) }}</span>
                </div>
                <div class="w-full bg-gray-100 rounded-full h-2">
                    <div class="bg-green-500 h-2 rounded-full" style="width: {{ $pctWa }}%"></div>
                </div>
                <div class="flex justify-between text-xs text-gray-400 mt-1">
                    <span>{{ $data['whatsapp_cloud_api']['ventanas_servicio_24h'] }} ventanas servicio</span>
                    <span>{{ $data['whatsapp_cloud_api']['templates_enviados'] }} templates</span>
                </div>
            </div>

            <hr class="border-gray-100">

            <div>
                <p class="text-xs font-medium text-gray-500 mb-2">Tokens consumidos</p>
                <div class="flex gap-3">
                    <div class="flex-1 bg-blue-50 rounded-lg p-3 text-center">
                        <p class="text-xs text-blue-600">Entrada</p>
                        <p class="font-bold text-blue-800 text-sm">{{ number_format($data['gemini_2_5_flash']['tokens_entrada']) }}</p>
                    </div>
                    <div class="flex-1 bg-purple-50 rounded-lg p-3 text-center">
                        <p class="text-xs text-purple-600">Salida</p>
                        <p class="font-bold text-purple-800 text-sm">{{ number_format($data['gemini_2_5_flash']['tokens_salida']) }}</p>
                    </div>
                </div>
            </div>

            <div class="bg-gray-50 rounded-lg p-3">
                <p class="text-xs font-medium text-gray-500 mb-1">Precios configurados (.env)</p>
                <p class="text-xs text-gray-600">Input: ${{ $data['gemini_2_5_flash']['precios_configurados']['input_por_1M_tokens_usd'] }} / 1M tokens</p>
                <p class="text-xs text-gray-600">Output: ${{ $data['gemini_2_5_flash']['precios_configurados']['output_por_1M_tokens_usd'] }} / 1M tokens</p>
            </div>
        </div>
    </div>

</div>

{{-- Calculadora de precio de venta --}}
<div class="bg-white rounded-xl border border-gray-200 p-5 mb-8">
    <h2 class="text-sm font-semibold text-gray-700 mb-4">Calculadora de precio de venta</h2>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
        <div class="bg-gray-50 rounded-lg p-4 text-center">
            <p class="text-xs text-gray-500 mb-1">Costo real (×1)</p>
            <p class="text-xl font-bold text-gray-700">${{ number_format($data['resumen']['total_usd'], 4) }} USD</p>
            <p class="text-sm text-gray-500">$ {{ number_format($data['resumen']['total_cop']) }} COP</p>
        </div>
        <div class="bg-yellow-50 rounded-lg p-4 text-center border-2 border-yellow-300">
            <p class="text-xs text-yellow-700 font-medium mb-1">Precio sugerido (×3)</p>
            <p class="text-xl font-bold text-yellow-800">${{ number_format($data['resumen']['sugerencia_cotizacion']['precio_x3_usd'], 2) }} USD</p>
            <p class="text-sm text-yellow-700">$ {{ number_format($data['resumen']['sugerencia_cotizacion']['precio_x3_cop']) }} COP</p>
        </div>
        <div class="bg-green-50 rounded-lg p-4 text-center">
            <p class="text-xs text-green-600 mb-1">Precio premium (×5)</p>
            <p class="text-xl font-bold text-green-700">${{ number_format($data['resumen']['total_usd'] * 5, 2) }} USD</p>
            <p class="text-sm text-green-600">$ {{ number_format($data['resumen']['total_usd'] * 5 * $trmCop) }} COP</p>
        </div>
    </div>
    <p class="text-xs text-gray-400 mt-3">
        * El precio ×3 cubre operación, soporte y margen mínimo. El precio ×5 incluye soporte prioritario y expansión.
        TRM usada: ${{ number_format($trmCop) }}.
    </p>
</div>

{{-- Tabla top usuarios --}}
@if($topUsuarios->isNotEmpty())
<div class="bg-white rounded-xl border border-gray-200 p-5">
    <h2 class="text-sm font-semibold text-gray-700 mb-4">Top usuarios por consumo de tokens</h2>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-xs text-gray-400 border-b border-gray-100">
                    <th class="text-left pb-3 font-medium">#</th>
                    <th class="text-left pb-3 font-medium">Teléfono</th>
                    <th class="text-right pb-3 font-medium">Conversaciones</th>
                    <th class="text-right pb-3 font-medium">Tokens totales</th>
                    <th class="text-right pb-3 font-medium">Costo estimado</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @php
                    $precioInputConf  = $data['gemini_2_5_flash']['precios_configurados']['input_por_1M_tokens_usd'];
                    $precioOutputConf = $data['gemini_2_5_flash']['precios_configurados']['output_por_1M_tokens_usd'];
                @endphp
                @foreach($topUsuarios as $i => $usuario)
                    @php
                        $costoUsuario = ($usuario->total_tokens / 1_000_000) * (($precioInputConf + $precioOutputConf) / 2);
                    @endphp
                    <tr class="hover:bg-gray-50 transition-colors">
                        <td class="py-3 text-gray-400">{{ $i + 1 }}</td>
                        <td class="py-3 font-mono text-gray-700">
                            +{{ substr($usuario->telefono_usuario, 0, 2) }}·····{{ substr($usuario->telefono_usuario, -4) }}
                        </td>
                        <td class="py-3 text-right text-gray-700">{{ $usuario->conversaciones }}</td>
                        <td class="py-3 text-right text-gray-700">{{ number_format($usuario->total_tokens) }}</td>
                        <td class="py-3 text-right font-medium text-gray-900">${{ number_format($costoUsuario, 5) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

@endsection
