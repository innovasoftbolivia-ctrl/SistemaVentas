@extends('layouts.app')

@php
    use App\Support\Config;

    $moneda = Config::moneda();
    $top = $masVendidos->take(10);

    $grafico = [
        'tipo' => 'bar',
        'moneda' => $moneda,
        'categorias' => $top->pluck('nombre')->map(fn ($n) => \Illuminate\Support\Str::limit($n, 18))->all(),
        'series' => [['name' => 'Vendido', 'data' => $top->pluck('monto_vendido')->map(fn ($m) => (float) $m)->all()]],
        'alto' => 340,
    ];
@endphp

@section('content')
    <div class="space-y-6">

        <x-common.rango-fechas :accion="route('reportes.productos')" :desde="$desde" :hasta="$hasta" />

        {{-- Inventario, que es una foto de hoy y no depende del rango --}}
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            @php
                $tarjetas = [
                    ['Productos en catálogo', number_format($inventario['productos']), 'text-gray-800 dark:text-white/90', null],
                    ['Valor al costo', Config::importe($inventario['costo']), 'text-gray-800 dark:text-white/90',
                        'capital inmovilizado'],
                    ['Valor a precio de venta', Config::importe($inventario['venta']), 'text-gray-800 dark:text-white/90',
                        'base, sin impuesto'],
                    ['Margen potencial', Config::importe($inventario['margen']), 'text-success-600 dark:text-success-500',
                        'si se vendiera todo'],
                ];
            @endphp

            @foreach ($tarjetas as [$etiqueta, $valor, $clase, $nota])
                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                    <p class="mb-1 text-theme-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $etiqueta }}</p>
                    <p class="text-title-sm font-semibold {{ $clase }}">{{ $valor }}</p>
                    @if ($nota)
                        <p class="mt-1 text-theme-xs text-gray-400 dark:text-gray-500">{{ $nota }}</p>
                    @endif
                </div>
            @endforeach
        </div>

        <p class="text-theme-xs text-gray-400 dark:text-gray-500">
            Las cifras de inventario son una foto de <b>hoy</b>: no dependen del período elegido, que sí filtra el
            ranking de más vendidos.
        </p>

        {{-- Alertas de stock --}}
        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="flex flex-wrap items-center justify-between gap-3 px-6 py-5">
                <div>
                    <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Alertas de reposición</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Productos que llegaron a su stock mínimo (objetivo O7).
                    </p>
                </div>
                @if ($alertas->isNotEmpty())
                    <x-ui.estado estado="SUSPENDIDO" :texto="$alertas->count().' producto(s)'" />
                @endif
            </div>

            <div class="max-w-full overflow-x-auto border-t border-gray-100 dark:border-gray-800">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Producto</th>
                            <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Categoría</th>
                            <th class="px-6 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Stock</th>
                            <th class="px-6 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Mínimo</th>
                            <th class="px-6 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Faltante</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($alertas as $alerta)
                            <tr class="transition hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                                <td class="px-6 py-3">
                                    <a href="{{ route('productos.show', $alerta->id) }}"
                                        class="block text-theme-sm text-gray-800 hover:text-brand-500 dark:text-white/90">
                                        {{ $alerta->nombre }}
                                    </a>
                                    <span class="font-mono text-theme-xs text-gray-400 dark:text-gray-500">
                                        {{ $alerta->codigo }}
                                    </span>
                                </td>
                                <td class="px-6 py-3 text-theme-sm text-gray-500 dark:text-gray-400">{{ $alerta->categoria }}</td>
                                <td class="px-6 py-3 text-right text-theme-sm font-medium {{ (float) $alerta->stock_actual <= 0 ? 'text-error-500' : 'text-warning-600 dark:text-orange-400' }}">
                                    {{ Config::cantidad($alerta->stock_actual) }}
                                </td>
                                <td class="px-6 py-3 text-right text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ Config::cantidad($alerta->stock_minimo) }}
                                </td>
                                <td class="px-6 py-3 text-right text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ Config::cantidad($alerta->faltante) }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-10 text-center text-theme-sm text-success-600 dark:text-success-500">
                                    Ningún producto está por debajo de su stock mínimo.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Más vendidos --}}
        @if ($masVendidos->isNotEmpty())
            <div class="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                <div class="px-6 py-5">
                    <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Más vendidos del período</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Por monto vendido neto de devoluciones. Los importes van sin impuesto.
                    </p>
                </div>
                <div class="px-3 pb-3">
                    <div data-apexchart="{{ json_encode($grafico) }}"></div>
                </div>
            </div>
        @endif

        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="px-6 py-5">
                <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Ranking del período</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Todas las cifras van <b>netas de devoluciones</b>: lo que el negocio se quedó. El margen es
                    estimado, porque compara lo vendido contra el precio de compra <b>actual</b> del producto, que
                    puede no ser el que tenía al venderse.
                </p>
            </div>

            <div class="max-w-full overflow-x-auto border-t border-gray-100 dark:border-gray-800">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">#</th>
                            <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Producto</th>
                            <th class="px-6 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Categoría</th>
                            <th class="px-6 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Unidades</th>
                            <th class="px-6 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Vendido</th>
                            <th class="px-6 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Margen</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($masVendidos as $i => $fila)
                            <tr class="transition hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                                <td class="px-6 py-3 text-theme-sm text-gray-400 dark:text-gray-500">{{ $i + 1 }}</td>
                                <td class="px-6 py-3">
                                    <a href="{{ route('productos.show', $fila->id) }}"
                                        class="block text-theme-sm text-gray-800 hover:text-brand-500 dark:text-white/90">
                                        {{ $fila->nombre }}
                                    </a>
                                    <span class="font-mono text-theme-xs text-gray-400 dark:text-gray-500">{{ $fila->codigo }}</span>
                                </td>
                                <td class="px-6 py-3 text-theme-sm text-gray-500 dark:text-gray-400">{{ $fila->categoria }}</td>
                                <td class="px-6 py-3 text-right text-theme-sm text-gray-500 dark:text-gray-400">
                                    {{ Config::cantidad($fila->unidades_vendidas) }}
                                    @if ((float) $fila->unidades_devueltas > 0)
                                        <span class="block text-theme-xs text-error-500">
                                            {{ Config::cantidad($fila->unidades_devueltas) }} devuelta(s)
                                        </span>
                                    @endif
                                </td>
                                <td class="px-6 py-3 text-right text-theme-sm font-medium text-gray-800 dark:text-white/90">
                                    {{ Config::importe($fila->monto_vendido) }}
                                </td>
                                <td class="px-6 py-3 text-right text-theme-sm font-medium {{ (float) $fila->margen_estimado >= 0 ? 'text-success-600 dark:text-success-500' : 'text-error-500' }}">
                                    {{ Config::importe($fila->margen_estimado) }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                                    No se vendió nada en el período.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
