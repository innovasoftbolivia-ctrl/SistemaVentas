@extends('layouts.app')

@php
    use App\Support\Config;
@endphp

@section('content')
    <div class="space-y-6">

        {{-- Qué productos --}}
        <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <form method="GET" action="{{ route('etiquetas.index') }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4 lg:items-start" data-filtros>
                <x-form.campo label="Buscar" for="buscar" help="Nombre, código interno o de barras.">
                    <x-form.input id="buscar" name="buscar" :value="$filtros['buscar']" placeholder="Arroz, P-0001…" />
                </x-form.campo>

                <x-form.campo label="Categoría" for="categoria">
                    <x-form.select id="categoria" name="categoria" :value="$filtros['categoria']"
                        placeholder="Todas" :opciones="$categorias" />
                </x-form.campo>

                <x-form.campo label="Precio cambiado" for="cambio" help="Los que cambiaron de precio en ese tiempo: lo habitual para reimprimir.">
                    <x-form.select id="cambio" name="cambio" :value="$filtros['cambio']" placeholder="Da igual"
                        :opciones="collect($plazos)->mapWithKeys(fn ($d) => [$d => $d === 1 ? 'Hoy' : 'En los últimos '.$d.' días'])->all()" />
                </x-form.campo>

                <div class="flex flex-col justify-end gap-2 pb-1 lg:pt-7">
                    <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-gray-400">
                        <input type="checkbox" name="oferta" value="1" @checked($filtros['oferta'])
                            class="h-4 w-4 rounded border-gray-300 text-brand-500">
                        Solo los que están en oferta hoy
                    </label>
                </div>

                <div class="flex flex-wrap gap-2 sm:col-span-2 lg:col-span-4">
                    <x-ui.button type="submit" size="sm">Filtrar</x-ui.button>
                    <x-ui.button variant="outline" size="sm" :href="route('etiquetas.index')">Limpiar</x-ui.button>
                </div>
            </form>
        </div>

        {{-- Cuáles, y cuántas copias --}}
        <form method="GET" action="{{ route('etiquetas.hoja') }}" target="_blank"
            x-data="{ ids: {{ $productos->count() }}, todos: true, marcar(valor) { this.todos = valor; $el.querySelectorAll('[data-producto]').forEach(c => c.checked = valor); this.ids = valor ? {{ $productos->count() }} : 0; }, contar() { this.ids = $el.querySelectorAll('[data-producto]:checked').length; } }"
            class="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">

            <div class="flex flex-wrap items-end justify-between gap-4 px-6 py-5">
                <div>
                    <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Productos</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        <span x-text="ids"></span> de {{ $productos->count() }} elegidos. Se imprime el precio que se cobra hoy;
                        si hay una oferta andando, sale con el de siempre tachado.
                    </p>
                </div>

                <div class="flex flex-wrap items-end gap-3">
                    <x-form.campo label="Copias de cada una" for="copias">
                        <x-form.input id="copias" name="copias" type="number" min="1" max="{{ $maxCopias }}" value="1" class="w-28" />
                    </x-form.campo>
                    <x-ui.button type="submit" ::disabled="ids === 0">Imprimir etiquetas</x-ui.button>
                </div>
            </div>

            @if ($recortado)
                <p class="border-t border-gray-100 px-6 py-3 text-theme-sm text-warning-700 dark:border-gray-800 dark:text-orange-400" data-recortado>
                    Son más de {{ $limite }} productos: se muestran los primeros {{ $limite }}. Filtra por categoría para ver el resto.
                </p>
            @endif

            <div class="max-w-full overflow-x-auto overscroll-x-contain border-t border-gray-100 dark:border-gray-800">
                <table class="min-w-full">
                    <thead class="border-b border-gray-100 dark:border-gray-800">
                        <tr>
                            <th class="w-12 px-6 py-3">
                                <input type="checkbox" checked aria-label="Elegir todos" @change="marcar($event.target.checked)"
                                    class="h-4 w-4 rounded border-gray-300 text-brand-500">
                            </th>
                            <th class="px-3 py-3 text-left text-theme-xs font-medium text-gray-500 dark:text-gray-400">Producto</th>
                            <th class="hidden px-3 py-3 text-left text-theme-xs font-medium text-gray-500 md:table-cell dark:text-gray-400">Código de barras</th>
                            <th class="px-6 py-3 text-right text-theme-xs font-medium text-gray-500 dark:text-gray-400">Precio</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @forelse ($productos as $producto)
                            <tr class="transition hover:bg-gray-50 dark:hover:bg-white/[0.02]" data-fila>
                                <td class="px-6 py-3">
                                    <input type="checkbox" name="ids[]" value="{{ $producto->id }}" checked data-producto
                                        aria-label="Imprimir la etiqueta de {{ $producto->nombre }}" @change="contar()"
                                        class="h-4 w-4 rounded border-gray-300 text-brand-500">
                                </td>
                                <td class="px-3 py-3 text-theme-sm text-gray-800 dark:text-white/90">
                                    {{ $producto->nombre }}
                                    <span class="block font-mono text-theme-xs text-gray-500 dark:text-gray-400">{{ $producto->codigo }}</span>
                                </td>
                                <td class="hidden px-3 py-3 font-mono text-theme-xs text-gray-500 md:table-cell dark:text-gray-400">
                                    {{ $producto->codigo_barras ?: '—' }}
                                    @if ($producto->codigo_barras && ! \App\Support\CodigoDeBarras::esValido($producto->codigo_barras))
                                        <span class="block font-sans text-warning-700 dark:text-orange-400" data-sin-barras>
                                            sale solo el número: no es un EAN válido
                                        </span>
                                    @endif
                                </td>
                                <td class="px-6 py-3 text-right text-theme-sm whitespace-nowrap text-gray-800 dark:text-white/90">
                                    {{ Config::importe($producto->precio_estante_vigente) }}
                                    @if ($producto->en_oferta)
                                        <span class="block text-theme-xs text-success-700 dark:text-success-500">oferta hasta {{ $producto->oferta_hasta->format('d/m') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-6 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                                    No hay productos con esos filtros.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </form>
    </div>
@endsection
