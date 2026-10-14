@extends('layouts.app')

@php
    use App\Support\Config;

    /*
     * Un informe en pantalla, dibujado desde el MISMO documento que alimenta el Excel y
     * el PDF (ver ExportaInformes): indicadores arriba, tablas debajo. Un informe nuevo
     * es solo un documento nuevo; esta vista no se toca.
     */
    $fmt = function ($valor, ?string $tipo) {
        if ($valor === null || $valor === '') {
            return '';
        }

        return match ($tipo) {
            'moneda' => Config::importe($valor),
            'entero' => number_format((float) $valor, 0, '.', ','),
            'decimal' => Config::cantidad($valor),
            'porcentaje' => number_format((float) $valor * 100, 1, '.', ',').' %',
            'fecha' => \Illuminate\Support\Carbon::parse($valor)->format('d/m/Y'),
            default => $valor,
        };
    };
@endphp

@section('content')
    <div class="space-y-6">

        @isset($aviso)
            <div data-aviso-archivo role="status"
                class="rounded-2xl border border-warning-200 bg-warning-50 p-4 text-theme-sm text-warning-700 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-orange-400">
                {{ $aviso }}
            </div>
        @endisset

        {{-- El filtro: un rango de fechas, o los días que se tolera sin vender. --}}
        @isset($desde)
            <x-common.rango-fechas :accion="$accion" :excel="$excel" :pdf="$pdf" :desde="$desde" :hasta="$hasta" />
        @endisset

        @isset($dias)
            <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p class="mb-1.5 text-sm font-medium text-gray-700 dark:text-gray-400">Sin una venta ni un ingreso en los últimos</p>
                        <div class="flex flex-wrap gap-2" data-plazos>
                            @foreach ($plazos as $plazo)
                                <a href="{{ $accion }}?dias={{ $plazo }}"
                                    class="rounded-lg px-3 py-2 text-theme-xs font-medium transition {{ $plazo === $dias
                                        ? 'bg-brand-500 text-white'
                                        : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-white/[0.05] dark:text-gray-400 dark:hover:bg-white/10' }}">
                                    {{ $plazo }} días
                                </a>
                            @endforeach
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <x-ui.button size="sm" variant="outline" :href="$excel">Excel</x-ui.button>
                        <x-ui.button size="sm" variant="outline" :href="$pdf">PDF</x-ui.button>
                    </div>
                </div>
            </div>
        @endisset

        {{-- Indicadores --}}
        @if (! empty($doc['indicadores']))
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-{{ min(max(count($doc['indicadores']), 2), 4) }}" data-indicadores>
                @foreach ($doc['indicadores'] as $ind)
                    <div class="rounded-2xl border bg-white p-5 dark:bg-white/[0.03] {{ ! empty($ind['destacar']) ? 'border-brand-200 dark:border-brand-800' : 'border-gray-200 dark:border-gray-800' }}">
                        <p class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $ind['etiqueta'] }}</p>
                        <p class="text-title-sm font-semibold {{ ! empty($ind['destacar']) ? 'text-brand-600 dark:text-brand-400' : 'text-gray-800 dark:text-white/90' }}">
                            {{ $fmt($ind['valor'], $ind['formato'] ?? null) }}
                        </p>
                        @if (! empty($ind['nota']))
                            <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">{{ $ind['nota'] }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        @isset($parcial)
            @include($parcial)
        @endisset

        {{-- Tablas --}}
        @foreach ($doc['tablas'] as $tabla)
            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]" data-tabla>
                <div class="px-6 py-5">
                    <h2 class="text-base font-medium text-gray-800 dark:text-white/90">{{ $tabla['nombre'] }}</h2>
                    @if (! empty($tabla['nota']))
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $tabla['nota'] }}</p>
                    @endif
                </div>

                <div class="max-w-full overflow-x-auto overscroll-x-contain border-t border-gray-100 dark:border-gray-800">
                    <table class="min-w-full">
                        <thead class="border-b border-gray-100 dark:border-gray-800">
                            <tr>
                                @foreach ($tabla['cabeceras'] as $i => $texto)
                                    <th class="px-6 py-3 text-theme-xs font-medium text-gray-500 dark:text-gray-400 {{ ($tabla['alineacion'][$i] ?? 'izq') === 'der' ? 'text-right' : 'text-left' }}">{{ $texto }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse ($tabla['filas'] as $fila)
                                <tr class="transition hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                                    @foreach (array_values($fila) as $i => $celda)
                                        <td class="px-6 py-3 text-theme-sm {{ $i === 0 ? 'text-gray-800 dark:text-white/90' : 'text-gray-500 dark:text-gray-400' }} {{ ($tabla['alineacion'][$i] ?? 'izq') === 'der' ? 'text-right' : '' }}">
                                            {{ $fmt($celda, $tabla['formatos'][$i] ?? null) }}
                                        </td>
                                    @endforeach
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ count($tabla['cabeceras']) }}" class="px-6 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                                        {{ $tabla['vacia'] ?? 'Sin datos en el período.' }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        @if (! empty($tabla['totales']) && count($tabla['filas']) > 0)
                            <tfoot class="border-t border-gray-200 dark:border-gray-700">
                                <tr>
                                    @foreach (array_values($tabla['totales']) as $i => $celda)
                                        <td class="px-6 py-3 text-theme-sm font-semibold text-gray-800 dark:text-white/90 {{ ($tabla['alineacion'][$i] ?? 'izq') === 'der' ? 'text-right' : '' }}">
                                            {{ is_string($celda) ? $celda : $fmt($celda, $tabla['formatos'][$i] ?? null) }}
                                        </td>
                                    @endforeach
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            </div>
        @endforeach
    </div>
@endsection
