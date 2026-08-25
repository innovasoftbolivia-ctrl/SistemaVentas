@extends('layouts.app')

@php
    use App\Support\Config;
@endphp

@section('content')
    @unless ($sesion)
        {{-- Sin caja abierta no se puede cobrar: no habría dónde imputar el dinero. --}}
        <div class="mx-auto max-w-xl">
            <x-common.component-card title="No tienes una caja abierta"
                desc="Cada venta se imputa a un turno de caja. Abre el tuyo para empezar a cobrar.">
                <x-ui.button :href="route('caja.index')" class="w-full">Ir a caja</x-ui.button>
            </x-common.component-card>
        </div>
    @else
        {{-- `pb-28` deja sitio en móvil a la barra flotante del carrito. --}}
        <div x-data="mostrador()" x-init="cargar()" @keydown.window="atajos($event)"
            class="grid grid-cols-1 gap-6 pb-28 xl:grid-cols-5 xl:pb-0">

            {{-- Catálogo --}}
            <div class="space-y-4 xl:col-span-3">
                <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                    <div class="flex flex-col gap-3 sm:flex-row">
                        <div class="relative flex-1">
                            <span class="absolute top-1/2 left-4 -translate-y-1/2 text-gray-400">
                                <svg aria-hidden="true" width="20" height="20" viewBox="0 0 20 20" fill="none">
                                    <path fill-rule="evenodd" clip-rule="evenodd"
                                        d="M3.04175 9.37363C3.04175 5.87693 5.87711 3.04199 9.37508 3.04199C12.8731 3.04199 15.7084 5.87693 15.7084 9.37363C15.7084 12.8703 12.8731 15.7053 9.37508 15.7053C5.87711 15.7053 3.04175 12.8703 3.04175 9.37363ZM9.37508 1.54199C5.04902 1.54199 1.54175 5.04817 1.54175 9.37363C1.54175 13.6991 5.04902 17.2053 9.37508 17.2053C11.2674 17.2053 13.003 16.5344 14.357 15.4176L17.177 18.238C17.4699 18.5309 17.9448 18.5309 18.2377 18.238C18.5306 17.9451 18.5306 17.4703 18.2377 17.1774L15.418 14.3573C16.5365 13.0033 17.2084 11.2669 17.2084 9.37363C17.2084 5.04817 13.7011 1.54199 9.37508 1.54199Z"
                                        fill="currentColor" />
                                </svg>
                            </span>
                            <input x-ref="buscador" x-model="q" @input.debounce.250ms="cargar()"
                                @keydown.enter.prevent="porCodigo()" type="text"
                                placeholder="Código de barras, código interno o nombre — luego Enter"
                                class="dark:bg-dark-900 shadow-theme-xs focus:border-brand-300 focus:ring-brand-500/10 h-12 w-full rounded-lg border border-gray-300 bg-transparent py-2.5 pr-4 pl-12 text-sm text-gray-800 placeholder:text-gray-400 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30" />
                        </div>

                        <select x-model="categoria" @change="cargar()"
                            class="dark:bg-dark-900 h-12 rounded-lg border border-gray-300 bg-transparent px-4 text-sm text-gray-800 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 sm:w-56">
                            <option value="">Todas las categorías</option>
                            @foreach ($categorias as $id => $nombre)
                                <option value="{{ $id }}">{{ $nombre }}</option>
                            @endforeach
                        </select>
                    </div>

                    <p class="mt-3 text-theme-xs text-gray-500 dark:text-gray-400">
                        <b>Enter</b> agrega el primer resultado · <b>F2</b> vuelve al buscador · <b>F4</b> cobra
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    <template x-for="p in productos" :key="p.id">
                        <button type="button" @click="agregar(p)" :disabled="p.stock <= 0"
                            class="rounded-2xl border border-gray-200 bg-white p-4 text-left transition hover:border-brand-300 hover:shadow-theme-md disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-brand-800">
                            {{-- Recuadro de medida fija con la foto ajustada dentro:
                                 así una botella vertical y una caja apaisada ocupan
                                 lo mismo y la cuadrícula no se descuadra. --}}
                            <template x-if="p.imagen">
                                <span class="mb-3 flex h-24 w-full items-center justify-center overflow-hidden rounded-xl border border-gray-100 bg-white p-1.5 dark:border-gray-700">
                                    <img :src="p.imagen" :alt="p.nombre" loading="lazy"
                                        class="max-h-full max-w-full object-scale-down" />
                                </span>
                            </template>

                            <template x-if="!p.imagen">
                                <span class="mb-3 flex h-24 w-full items-center justify-center rounded-xl border border-dashed border-gray-200 bg-gray-50 text-gray-300 dark:border-gray-700 dark:bg-white/[0.02] dark:text-gray-600">
                                    <svg aria-hidden="true" class="h-8 w-8" viewBox="0 0 24 24" fill="none">
                                        <path d="M3.75 7.25 12 3.5l8.25 3.75-8.25 3.75L3.75 7.25Z" stroke="currentColor"
                                            stroke-width="1.5" stroke-linejoin="round" />
                                        <path d="M3.75 12 12 15.75 20.25 12M3.75 16.75 12 20.5l8.25-3.75"
                                            stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                                            stroke-linejoin="round" />
                                    </svg>
                                </span>
                            </template>

                            <p class="mb-1 line-clamp-2 min-h-10 text-theme-sm font-medium text-gray-800 dark:text-white/90"
                                x-text="p.nombre"></p>
                            <p class="font-mono text-theme-xs text-gray-500 dark:text-gray-400" x-text="p.codigo"></p>
                            <div class="mt-2 flex items-end justify-between">
                                <span class="text-base font-semibold text-brand-500 dark:text-brand-400"
                                    x-text="'{{ $moneda }} ' + p.precio_estante.toFixed(2)"></span>
                                <span class="text-theme-xs"
                                    :class="p.stock <= 0 ? 'text-error-600 dark:text-error-400' : 'text-gray-500 dark:text-gray-400'"
                                    x-text="p.stock <= 0 ? 'agotado' : (cantidadTexto(p.stock) + ' ' + p.unidad)"></span>
                            </div>
                        </button>
                    </template>

                    <p x-show="!productos.length" class="col-span-full py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                        No hay productos que coincidan con la búsqueda.
                    </p>
                </div>
            </div>

            {{-- Carrito y cobro --}}
            <div class="xl:col-span-2">
                {{-- En escritorio el carrito acompaña el desplazamiento; en móvil
                     va después de la cuadrícula y se llega a él con la barra
                     flotante de abajo. --}}
                <form method="POST" action="{{ route('pos.store') }}" @submit="preparar($event)" x-ref="carrito"
                    class="flex flex-col rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03] xl:sticky xl:top-24 xl:max-h-[calc(100vh-11rem)] xl:overflow-y-auto overscroll-contain">
                    @csrf
                    <div x-ref="campos"></div>

                    <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-800">
                        <div class="flex items-center justify-between">
                            <h2 class="text-base font-medium text-gray-800 dark:text-white/90">Carrito</h2>
                            <button type="button" x-show="carrito.length" @click="carrito = []"
                                class="text-theme-xs text-error-600 dark:text-error-400 hover:text-error-600">Vaciar</button>
                        </div>
                        <p class="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                            {{ $sesion->caja?->nombre }} · turno abierto por {{ $sesion->usuarioApertura?->usuario ?? auth()->user()->usuario }}
                        </p>
                    </div>

                    {{--
                        El alto mínimo no es decorativo: con `flex-1` y sin él, en
                        una pantalla baja la lista se encoge hasta CERO y los
                        artículos del carrito —con sus botones de cantidad—
                        desaparecen sin previo aviso.

                        Lo que evita que el cobro quede fuera de alcance no es
                        encoger esta lista, sino el `overflow-y-auto overscroll-contain` del panel:
                        si el contenido no cabe, se desplaza dentro del panel.
                    --}}
                    <div class="min-h-40 flex-1 divide-y divide-gray-100 overflow-y-auto overscroll-contain dark:divide-gray-800">
                        <template x-for="(l, i) in carrito" :key="l.producto_id">
                            <div class="px-5 py-3">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="truncate text-theme-sm font-medium text-gray-800 dark:text-white/90"
                                            x-text="l.nombre"></p>
                                        <p class="text-theme-xs text-gray-500 dark:text-gray-400"
                                            x-text="'{{ $moneda }} ' + l.precio_estante.toFixed(2) + ' × ' + cantidadTexto(l.cantidad) + ' ' + l.unidad"></p>
                                    </div>
                                    <button type="button" @click="quitar(i)" title="Quitar"
                                        class="rounded-lg p-1 text-gray-400 transition hover:bg-error-50 hover:text-error-500 dark:hover:bg-error-500/10">
                                        <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none">
                                            <path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2"
                                                stroke-linecap="round" />
                                        </svg>
                                    </button>
                                </div>

                                <div class="mt-2 flex items-center justify-between gap-2">
                                    <div class="flex items-center gap-1">
                                        <button type="button" @click="sumar(i, -1)"
                                            class="h-8 w-8 rounded-lg border border-gray-200 text-gray-600 transition hover:bg-gray-100 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-white/[0.05]">−</button>
                                        <input type="number" inputmode="decimal" :step="l.decimal ? '0.001' : '1'" min="0"
                                            x-model.number="l.cantidad" @change="normalizar(i)"
                                            class="dark:bg-dark-900 h-8 w-20 rounded-lg border border-gray-300 bg-transparent px-2 text-center text-sm text-gray-800 focus:ring-2 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                                        <button type="button" @click="sumar(i, 1)"
                                            class="h-8 w-8 rounded-lg border border-gray-200 text-gray-600 transition hover:bg-gray-100 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-white/[0.05]">+</button>
                                    </div>
                                    <span class="text-theme-sm font-semibold text-gray-800 dark:text-white/90"
                                        x-text="'{{ $moneda }} ' + (l.precio_estante * l.cantidad).toFixed(2)"></span>
                                </div>

                                <p x-show="l.cantidad > l.stock" class="mt-1 text-theme-xs text-error-600 dark:text-error-400">
                                    Solo quedan <span x-text="cantidadTexto(l.stock)"></span> <span x-text="l.unidad"></span>.
                                </p>
                            </div>
                        </template>

                        <p x-show="!carrito.length" class="px-5 py-10 text-center text-theme-sm text-gray-500 dark:text-gray-400">
                            Busca un producto o pasa el lector de código de barras.
                        </p>
                    </div>

                    {{-- Cliente --}}
                    <div class="border-t border-gray-100 px-5 py-4 dark:border-gray-800">
                        <label for="cliente" class="mb-1.5 block text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                            Cliente
                        </label>
                        <select id="cliente" x-model.number="clienteId"
                            class="dark:bg-dark-900 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90">
                            <option value="">{{ $clienteGenerico }} (venta al paso — recibo)</option>
                            @foreach ($clientes as $c)
                                <option value="{{ $c['id'] }}" data-juridica="{{ $c['juridica'] ? '1' : '0' }}">
                                    {{ $c['etiqueta'] }}{{ $c['juridica'] ? ' — factura' : '' }}
                                </option>
                            @endforeach
                        </select>
                        <p class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                            Persona jurídica recibe <b>factura</b>; el resto, <b>recibo</b>.
                            <a href="{{ route('clientes.index') }}" class="text-brand-500 dark:text-brand-400 hover:text-brand-600">Registrar cliente</a>
                        </p>
                    </div>

                    {{-- Totales --}}
                    {{-- `aria-live="polite"`: el total cambia solo, al añadir un
                         artículo o teclear un descuento. Sin esto, quien usa
                         lector de pantalla cobra sin haber oído el importe. --}}
                    <div class="space-y-2 border-t border-gray-100 px-5 py-4 dark:border-gray-800"
                        aria-live="polite" aria-atomic="true">
                        <div class="flex justify-between text-theme-sm text-gray-500 dark:text-gray-400">
                            <span>Subtotal (base)</span>
                            <span x-text="'{{ $moneda }} ' + subtotal.toFixed(2)"></span>
                        </div>

                        <div class="flex items-center justify-between gap-3">
                            <label for="descuento" class="text-theme-sm text-gray-500 dark:text-gray-400">Descuento</label>
                            <input id="descuento" type="number" inputmode="decimal" step="0.01" min="0" x-model.number="descuento"
                                class="dark:bg-dark-900 h-9 w-28 rounded-lg border border-gray-300 bg-transparent px-2 text-right text-sm text-gray-800 focus:ring-2 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                        </div>

                        <p x-show="excedeDescuento" class="text-theme-xs text-warning-700 dark:text-orange-400">
                            @if ($puedeDescontar)
                                Este descuento supera el {{ $descuentoMaximo }}% habitual. Queda registrado a tu nombre.
                            @else
                                Tu rol permite hasta {{ $descuentoMaximo }}%. Por encima necesita autorización.
                            @endif
                        </p>

                        @if ($tasaImpuesto > 0)
                            <div class="flex justify-between text-theme-sm text-gray-500 dark:text-gray-400">
                                <span>Impuesto ({{ number_format($tasaImpuesto * 100, 0) }}%)</span>
                                <span x-text="'{{ $moneda }} ' + impuesto.toFixed(2)"></span>
                            </div>
                        @endif

                        <div class="flex items-baseline justify-between border-t border-gray-100 pt-2 dark:border-gray-800">
                            <span class="font-medium text-gray-800 dark:text-white/90">Total</span>
                            <span class="text-title-sm font-semibold text-brand-500 dark:text-brand-400"
                                x-text="'{{ $moneda }} ' + total.toFixed(2)"></span>
                        </div>
                    </div>

                    {{-- Pago --}}
                    <div class="space-y-3 border-t border-gray-100 px-5 py-4 dark:border-gray-800">
                        <div class="flex flex-wrap gap-2">
                            @foreach ($metodosPago as $metodo)
                                <button type="button" @click="metodoId = {{ $metodo->id }}"
                                    :class="metodoId === {{ $metodo->id }}
                                        ? 'bg-brand-500 text-white'
                                        : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-white/[0.05] dark:text-gray-400 dark:hover:bg-white/10'"
                                    class="rounded-lg px-3 py-2 text-theme-xs font-medium transition">
                                    {{ $metodo->nombre }}
                                </button>
                            @endforeach
                        </div>

                        <template x-if="esEfectivo">
                            <div>
                                <label for="recibido" class="mb-1.5 block text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                                    Efectivo recibido
                                </label>
                                <input id="recibido" type="number" inputmode="decimal" step="0.01" min="0" x-model.number="recibido"
                                    :placeholder="total.toFixed(2)"
                                    class="dark:bg-dark-900 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />

                                <div class="mt-2 flex flex-wrap gap-1.5">
                                    <template x-for="s in sugerencias" :key="s">
                                        <button type="button" @click="recibido = s"
                                            class="rounded-lg bg-gray-100 px-2.5 py-1 text-theme-xs text-gray-600 transition hover:bg-gray-200 dark:bg-white/[0.05] dark:text-gray-400 dark:hover:bg-white/10"
                                            x-text="'{{ $moneda }} ' + s.toFixed(2)"></button>
                                    </template>
                                </div>

                                <div x-show="recibido >= total && total > 0"
                                    class="mt-3 flex items-baseline justify-between rounded-xl bg-success-50 px-4 py-3 dark:bg-success-500/10">
                                    <span class="text-theme-sm font-medium text-success-700 dark:text-success-500">Vuelto</span>
                                    <span class="text-lg font-semibold text-success-700 dark:text-success-500"
                                        x-text="'{{ $moneda }} ' + vuelto.toFixed(2)"></span>
                                </div>
                            </div>
                        </template>

                        <template x-if="!esEfectivo">
                            <div>
                                <label for="referencia" class="mb-1.5 block text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                                    Número de operación
                                </label>
                                <input id="referencia" type="text" x-model="referencia" placeholder="Opcional"
                                    class="dark:bg-dark-900 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-3 text-sm text-gray-800 focus:ring-3 focus:outline-hidden dark:border-gray-700 dark:bg-gray-900 dark:text-white/90" />
                            </div>
                        </template>

                        <button type="submit" x-ref="cobrar" :disabled="!puedeCobrar || enviando"
                            class="flex w-full items-center justify-center rounded-lg bg-brand-500 px-4 py-3.5 text-sm font-medium text-white transition hover:bg-brand-600 disabled:cursor-not-allowed disabled:bg-brand-300">
                            <span x-show="!enviando" x-text="'Cobrar {{ $moneda }} ' + total.toFixed(2)"></span>
                            <span x-show="enviando">Registrando…</span>
                        </button>

                        <p x-show="carrito.length && !puedeCobrar && !enviando"
                            class="text-center text-theme-xs text-gray-500 dark:text-gray-400" x-text="motivoBloqueo"></p>
                    </div>
                </form>
            </div>

            {{-- Barra flotante: en una pantalla de teléfono el carrito queda
                 debajo de toda la cuadrícula, así que el total y el acceso a
                 cobrar acompañan siempre al cajero. --}}
            <div x-show="carrito.length" x-cloak
                class="fixed inset-x-0 bottom-0 z-40 border-t border-gray-200 bg-white/95 p-3 backdrop-blur dark:border-gray-800 dark:bg-gray-900/95 xl:hidden">
                <button type="button" @click="$refs.carrito.scrollIntoView({ behavior: 'smooth', block: 'start' })"
                    class="flex w-full items-center justify-between gap-3 rounded-xl bg-brand-500 px-4 py-3 text-white transition hover:bg-brand-600">
                    <span class="flex items-center gap-2 text-theme-sm">
                        <svg aria-hidden="true" class="h-5 w-5" viewBox="0 0 24 24" fill="none">
                            <path d="M2.75 4h1.6a1 1 0 0 1 .98.8l.42 2.1m0 0 1.5 7.1a1.5 1.5 0 0 0 1.47 1.2h8.06a1.5 1.5 0 0 0 1.4-.96l2.32-5.83a1 1 0 0 0-.93-1.37H5.75Z"
                                stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <span x-text="carrito.length"></span>
                        <span x-text="carrito.length === 1 ? 'artículo' : 'artículos'"></span>
                    </span>
                    <span class="text-base font-semibold" x-text="'{{ $moneda }} ' + total.toFixed(2)"></span>
                </button>
            </div>
        </div>

        @push('scripts')
            <script>
                function mostrador() {
                    return {
                        q: '',
                        categoria: '',
                        productos: [],
                        carrito: [],
                        clienteId: {{ (int) request('cliente') ?: 'null' }},
                        descuento: 0,
                        metodoId: {{ $metodosPago->first()?->id ?? 'null' }},
                        recibido: null,
                        referencia: '',
                        enviando: false,

                        tasa: {{ $tasaImpuesto }},
                        maxDescuento: {{ $descuentoMaximo }},
                        puedeDescontar: {{ $puedeDescontar ? 'true' : 'false' }},
                        efectivos: @js($metodosPago->where('codigo', 'EFECTIVO')->pluck('id')->values()),

                        async cargar() {
                            const url = new URL('{{ route('pos.productos') }}', window.location.origin);
                            url.searchParams.set('q', this.q);
                            if (this.categoria) url.searchParams.set('categoria', this.categoria);

                            const respuesta = await fetch(url, { headers: { 'Accept': 'application/json' } });
                            this.productos = await respuesta.json();
                        },

                        /* Con el lector, el código llega completo y termina en Enter. */
                        porCodigo() {
                            const exacto = this.productos.find(
                                p => p.codigo_barras === this.q.trim() || p.codigo === this.q.trim()
                            );
                            const elegido = exacto ?? this.productos[0];

                            if (elegido) {
                                this.agregar(elegido);
                                this.q = '';
                                this.cargar();
                            }
                        },

                        agregar(p) {
                            if (p.stock <= 0) return;

                            const linea = this.carrito.find(l => l.producto_id === p.id);

                            if (linea) {
                                if (linea.cantidad + 1 > p.stock) return;
                                linea.cantidad = Math.round((linea.cantidad + 1) * 1000) / 1000;
                                return;
                            }

                            this.carrito.push({
                                producto_id: p.id,
                                nombre: p.nombre,
                                precio: p.precio,
                                precio_estante: p.precio_estante,
                                afecto: p.afecto,
                                cantidad: 1,
                                stock: p.stock,
                                unidad: p.unidad,
                                decimal: p.decimal,
                            });
                        },

                        sumar(i, delta) {
                            const l = this.carrito[i];
                            const paso = l.decimal ? 0.5 : 1;
                            const nueva = Math.round((l.cantidad + delta * paso) * 1000) / 1000;

                            if (nueva <= 0) return this.quitar(i);
                            if (nueva > l.stock) return;

                            l.cantidad = nueva;
                        },

                        normalizar(i) {
                            const l = this.carrito[i];
                            let c = Number(l.cantidad) || 0;

                            if (!l.decimal) c = Math.round(c);
                            if (c <= 0) return this.quitar(i);

                            l.cantidad = Math.min(c, l.stock);
                        },

                        quitar(i) {
                            this.carrito.splice(i, 1);
                        },

                        cantidadTexto(n) {
                            return Number(n).toFixed(3).replace(/\.?0+$/, '');
                        },

                        /* Los precios se guardan sin impuesto; el total lo lleva encima. */
                        get subtotal() {
                            return this.redondear(
                                this.carrito.reduce((s, l) => s + this.redondear(l.precio * l.cantidad), 0)
                            );
                        },

                        get impuesto() {
                            /* Se redondea POR LÍNEA, igual que la columna generada
                               `impuesto_linea`: sumar sin redondear daría otro total. */
                            const bruto = this.carrito.reduce(
                                (s, l) => s + (l.afecto
                                    ? this.redondear(this.redondear(l.precio * l.cantidad) * this.tasa)
                                    : 0), 0
                            );
                            /* El descuento de cabecera se prorratea, igual que en sp_recalcular_venta. */
                            const factor = this.subtotal > 0 ? (this.subtotal - this.descuentoValido) / this.subtotal : 0;

                            return this.redondear(bruto * factor);
                        },

                        get descuentoValido() {
                            const d = Number(this.descuento) || 0;
                            return Math.min(Math.max(d, 0), this.subtotal);
                        },

                        get total() {
                            return this.redondear(this.subtotal - this.descuentoValido + this.impuesto);
                        },

                        get excedeDescuento() {
                            if (this.descuentoValido <= 0 || this.subtotal <= 0) return false;
                            return (this.descuentoValido / this.subtotal * 100) > this.maxDescuento;
                        },

                        get esEfectivo() {
                            return this.efectivos.includes(this.metodoId);
                        },

                        get vuelto() {
                            return this.redondear(Math.max((Number(this.recibido) || 0) - this.total, 0));
                        },

                        /* Billetes con los que suele pagar la gente. */
                        get sugerencias() {
                            const t = this.total;
                            if (t <= 0) return [];

                            const billetes = [10, 20, 50, 100, 200];
                            const opciones = new Set([Math.ceil(t)]);

                            billetes.filter(b => b >= t).forEach(b => opciones.add(b));

                            return [...opciones].sort((a, b) => a - b).slice(0, 4);
                        },

                        get sinStock() {
                            return this.carrito.some(l => l.cantidad > l.stock);
                        },

                        get puedeCobrar() {
                            if (!this.carrito.length || this.total <= 0 || this.sinStock) return false;
                            if (this.excedeDescuento && !this.puedeDescontar) return false;
                            if (this.esEfectivo && this.recibido !== null && this.recibido !== ''
                                && Number(this.recibido) < this.total) return false;

                            return true;
                        },

                        get motivoBloqueo() {
                            if (this.sinStock) return 'Hay líneas por encima del stock disponible.';
                            if (this.excedeDescuento && !this.puedeDescontar) return 'El descuento necesita autorización.';
                            if (this.esEfectivo && Number(this.recibido) < this.total) return 'El efectivo recibido no alcanza.';
                            return '';
                        },

                        redondear(n) {
                            return Math.round((Number(n) + Number.EPSILON) * 100) / 100;
                        },

                        /* Se arma el formulario en el momento de enviar: el carrito vive en Alpine. */
                        preparar(e) {
                            if (!this.puedeCobrar || this.enviando) {
                                e.preventDefault();
                                return;
                            }

                            this.enviando = true;

                            const campos = this.$refs.campos;
                            campos.innerHTML = '';

                            const oculto = (name, value) => {
                                const input = document.createElement('input');
                                input.type = 'hidden';
                                input.name = name;
                                input.value = value;
                                campos.appendChild(input);
                            };

                            this.carrito.forEach((l, i) => {
                                oculto(`lineas[${i}][producto_id]`, l.producto_id);
                                oculto(`lineas[${i}][cantidad]`, l.cantidad);
                                oculto(`lineas[${i}][precio_unitario]`, l.precio);
                            });

                            /* No se manda el importe: el servidor cobra su propio total.
                               Así un céntimo de diferencia en el redondeo del navegador
                               no puede tumbar la venta. */
                            oculto('pagos[0][metodo_pago_id]', this.metodoId);

                            if (this.esEfectivo && Number(this.recibido) >= this.total) {
                                oculto('pagos[0][monto_recibido]', Number(this.recibido).toFixed(2));
                            } else if (!this.esEfectivo && this.referencia) {
                                oculto('pagos[0][referencia]', this.referencia);
                            }

                            if (this.clienteId) oculto('cliente_id', this.clienteId);
                            if (this.descuentoValido > 0) oculto('descuento', this.descuentoValido.toFixed(2));
                        },

                        atajos(e) {
                            if (e.key === 'F2') {
                                e.preventDefault();
                                this.$refs.buscador.focus();
                                this.$refs.buscador.select();
                            }

                            if (e.key === 'F4' && this.puedeCobrar) {
                                e.preventDefault();
                                this.$refs.cobrar.click();
                            }
                        },
                    };
                }
            </script>
        @endpush
    @endunless
@endsection
