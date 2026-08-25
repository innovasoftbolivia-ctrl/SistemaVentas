@extends('layouts.app')

@php
    use App\Support\Config;

    $moneda = Config::moneda();

    $grafico = [
        'tipo' => 'area',
        'moneda' => $moneda,
        'categorias' => array_column($porDia, 'etiqueta'),
        'series' => [['name' => 'Vendido', 'data' => array_column($porDia, 'monto')]],
        'alto' => 320,
    ];

    $graficoMetodos = [
        'tipo' => 'donut',
        'moneda' => $moneda,
        'etiquetas' => $porMetodo->pluck('metodo_pago')->all(),
        'series' => $porMetodo->pluck('monto')->map(fn ($m) => (float) $m)->all(),
        'leyenda' => true,
        'alto' => 300,
    ];
@endphp

@section('content')
    <div class="space-y-6">

        <x-common.rango-fechas :accion="route('reportes.ventas')" :desde="$desde" :hasta="$hasta" />

        {{-- Cifras del período --}}
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            @php
                $tarjetas = [
                    ['Vendido', Config::importe($resumen['vendido']), 'text-gray-800 dark:text-white/90',
                        $resumen['operaciones'].' operación(es)'],
                    ['Neto', Config::importe($resumen['neto']), 'text-success-700 dark:text-success-500',
                        'descontando devoluciones'],
                    ['Ticket promedio', Config::importe($resumen['ticket']), 'text-gray-800 dark:text-white/90',
                        'por operación'],
                    ['Impuesto', Config::importe($resumen['impuesto']), 'text-gray-800 dark:text-white/90',
                        'incluido en el vendido'],
                ];
            @endphp

            @foreach ($tarjetas as [$etiqueta, $valor, $clase, $nota])
                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                    <p class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $etiqueta }}</p>
                    <p class="text-title-sm font-semibold {{ $clase }}">{{ $valor }}</p>
                    <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">{{ $nota }}</p>
                </div>
            @endforeach
        </div>

        @if ($resumen['anuladas'] > 0 || $resumen['devuelto'] > 0)
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                    <p class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Anuladas</p>
                    <p class="text-title-sm font-semibold text-error-600 dark:text-error-400">{{ number_format($resumen['anuladas']) }}</p>
                    <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">no cuentan en el vendido</p>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                    <p class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Devuelto</p>
                    <p class="text-title-sm font-semibold text-error-600 dark:text-error-400">{{ Config::importe($resumen['devuelto']) }}</p>
                    <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">salió del cajón</p>
                </div>
            </div>
        @endif

        {{-- Evolución diaria --}}
        <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="px-6 py-5">
                <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Ventas por día</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Los días sin ventas se dibujan en cero: unir dos días lejanos con una recta aparentaría ventas que
                    no existieron.
                </p>
            </div>
            <div class="px-3 pb-3">
                <div data-apexchart="{{ json_encode($grafico) }}"></div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            {{-- Método de pago --}}
            <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="px-6 py-5">
                    <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Por método de pago</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Solo el efectivo queda en el cajón; el resto no cuenta para el arqueo.
                    </p>
                </div>

                @if ($porMetodo->isEmpty())
                    <p class="px-6 pb-6 text-theme-sm text-gray-500 dark:text-gray-400">
                        Sin cobros en el período.
                    </p>
                @else
                    <div class="px-3">
                        <div data-apexchart="{{ json_encode($graficoMetodos) }}"></div>
                    </div>

                    <div class="border-t border-gray-100 dark:border-gray-800">
                        <table class="min-w-full">
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach ($porMetodo as $fila)
                                    <tr>
                                        <td class="px-6 py-3 text-theme-sm text-gray-800 dark:text-white/90">
                                            {{ $fila->metodo_pago }}
                                            <span class="block text-theme-xs text-gray-500 dark:text-gray-400">
                                                {{ $fila->ventas }} venta(s)
                                            </span>
                                        </td>
                                        <td class="px-6 py-3 text-right text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                            {{ Config::importe($fila->monto) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- Por cajero --}}
            <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="px-6 py-5">
                    <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Por cajero</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Quién vendió cuánto en el período.
                    </p>
                </div>

                <div class="max-w-full overflow-x-auto overscroll-contain border-t border-gray-100 dark:border-gray-800">
                    <table class="min-w-full">
                        <thead class="border-b border-gray-100 dark:border-gray-800">
                            <tr>
                                <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Cajero</th>
                                <th class="px-6 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Ventas</th>
                                <th class="px-6 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Ticket</th>
                                <th class="px-6 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Monto</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse ($porCajero as $fila)
                                <tr>
                                    <td class="px-6 py-3">
                                        <span class="block text-theme-sm text-gray-800 dark:text-white/90">
                                            {{ $fila->empleado }}
                                        </span>
                                        <span class="text-theme-xs text-gray-500 dark:text-gray-400">{{ $fila->usuario }}</span>
                                    </td>
                                    <td class="px-6 py-3 text-right text-theme-sm text-gray-500 dark:text-gray-400">
                                        {{ $fila->ventas }}
                                    </td>
                                    <td class="px-6 py-3 text-right text-theme-sm text-gray-500 dark:text-gray-400">
                                        {{ Config::importe($fila->ticket) }}
                                    </td>
                                    <td class="px-6 py-3 text-right text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                        {{ Config::importe($fila->monto) }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-6 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                                        Sin ventas en el período.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- Detalle diario --}}
        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="px-6 py-5">
                <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Detalle por día</h2>
            </div>

            <div class="max-w-full overflow-x-auto overscroll-contain border-t border-gray-100 dark:border-gray-800">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Día</th>
                            <th class="px-6 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Ventas</th>
                            <th class="px-6 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Ticket promedio</th>
                            <th class="px-6 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Monto</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach (array_reverse(array_filter($porDia, fn ($d) => $d['ventas'] > 0)) as $dia)
                            <tr class="transition hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                                <td class="px-6 py-3 text-theme-sm text-gray-800 dark:text-white/90">
                                    <a href="{{ route('ventas.index', ['desde' => $dia['dia'], 'hasta' => $dia['dia']]) }}"
                                        class="hover:text-brand-500">
                                        {{ \Illuminate\Support\Carbon::parse($dia['dia'])->format('d/m/Y') }}
                                    </a>
                                </td>
                                <td class="px-6 py-3 text-right text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ $dia['ventas'] }}
                                </td>
                                <td class="px-6 py-3 text-right text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ Config::importe($dia['ticket']) }}
                                </td>
                                <td class="px-6 py-3 text-right text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                    {{ Config::importe($dia['monto']) }}
                                </td>
                            </tr>
                        @endforeach

                        @if (collect($porDia)->every(fn ($d) => $d['ventas'] === 0))
                            <tr>
                                <td colspan="4" class="px-6 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                                    No hubo ventas en el período.
                                </td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
