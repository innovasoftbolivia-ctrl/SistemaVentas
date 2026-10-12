{{-- El mapa de calor de las ventas: días en las filas, horas en las columnas. Cuanto más
     oscuro, más se vendió. Solo en pantalla; las tablas de abajo son lo que se exporta. --}}
@if (! empty($mapa['filas']))
    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]" data-mapa-horas>
        <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Cuándo se vende</h2>
        <p class="mt-1 mb-4 text-sm text-gray-500 dark:text-gray-400">
            Cuanto más oscuro el cuadro, más se vendió en esa hora de ese día. El número es la cantidad de ventas.
        </p>

        <div class="overflow-x-auto">
            <table class="min-w-full border-separate border-spacing-1 text-center text-theme-xs">
                <thead>
                    <tr>
                        <th class="w-24"></th>
                        @foreach ($mapa['horas'] as $hora)
                            <th class="px-1 font-medium text-gray-500 dark:text-gray-400">{{ sprintf('%02d', $hora) }}h</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($mapa['filas'] as $fila)
                        <tr>
                            <th class="pr-2 text-left font-medium text-gray-700 dark:text-gray-300">{{ $fila['dia'] }}</th>
                            @foreach ($fila['celdas'] as $celda)
                                <td class="h-9 min-w-9 rounded-md text-gray-800 dark:text-white"
                                    style="background-color: rgba(70, 95, 255, {{ max($celda['intensidad'], $celda['ventas'] > 0 ? 0.08 : 0.03) }})"
                                    title="{{ $celda['ventas'] }} venta(s) · {{ \App\Support\Config::importe($celda['monto']) }}">
                                    {{ $celda['ventas'] > 0 ? $celda['ventas'] : '' }}
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
