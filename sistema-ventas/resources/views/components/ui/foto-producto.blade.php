@props([
    'producto' => null,
    'url' => null,
    'nombre' => null,
    'size' => 'md',
])

@php
    // Sirve tanto con un modelo como con datos sueltos (el mostrador arma sus
    // tarjetas desde JSON, no desde Eloquent).
    $imagen = $url ?? $producto?->imagen_url;
    $titulo = $nombre ?? $producto?->nombre ?? '';

    // El recuadro tiene medida fija para que la cuadrícula no se descuadre;
    // la foto se acomoda dentro, sin importar su proporción.
    $sizeMap = [
        'sm' => ['caja' => 'h-11 w-11 rounded-lg', 'aire' => 'p-0.5', 'icono' => 'h-5 w-5'],
        'md' => ['caja' => 'h-16 w-16 rounded-xl', 'aire' => 'p-1', 'icono' => 'h-7 w-7'],
        'lg' => ['caja' => 'h-28 w-28 rounded-2xl', 'aire' => 'p-2', 'icono' => 'h-10 w-10'],
        'xl' => ['caja' => 'aspect-square w-full rounded-2xl', 'aire' => 'p-3', 'icono' => 'h-12 w-12'],
    ];

    $medida = $sizeMap[$size] ?? $sizeMap['md'];

    $marco = 'flex flex-none items-center justify-center overflow-hidden border '
        .$medida['caja'].' '.$medida['aire'];
@endphp

@if ($imagen)
    {{--
        `object-scale-down` y no `object-cover`: la foto entra entera, sin
        recortes ni deformación, sea cuadrada, vertical o apaisada. Y a
        diferencia de `object-contain`, no agranda una imagen pequeña más allá
        de su tamaño real, que solo la vería pixelada.

        El fondo se mantiene claro en ambos temas: casi todas las fotos de
        producto vienen recortadas sobre blanco y sobre oscuro se verían con un
        marco fantasma alrededor.
    --}}
    <span
        {{ $attributes->merge([
            'class' => $marco.' border-gray-200 bg-white dark:border-gray-700',
        ]) }}>
        <img src="{{ $imagen }}" alt="{{ $titulo }}" loading="lazy"
            class="max-h-full max-w-full object-scale-down" />
    </span>
@else
    {{-- Marcador: la misma caja, para que la fila no se descuadre sin foto. --}}
    <span
        {{ $attributes->merge([
            'class' => $marco.' border-dashed border-gray-200 bg-gray-50 text-gray-300 '
                .'dark:border-gray-700 dark:bg-white/[0.02] dark:text-gray-600',
        ]) }}
        title="{{ $titulo ? $titulo.' — sin foto' : 'Sin foto' }}">
        <svg aria-hidden="true" class="{{ $medida['icono'] }}" viewBox="0 0 24 24" fill="none">
            <path d="M3.75 7.25 12 3.5l8.25 3.75-8.25 3.75L3.75 7.25Z" stroke="currentColor" stroke-width="1.5"
                stroke-linejoin="round" />
            <path d="M3.75 12 12 15.75 20.25 12M3.75 16.75 12 20.5l8.25-3.75" stroke="currentColor"
                stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
    </span>
@endif
