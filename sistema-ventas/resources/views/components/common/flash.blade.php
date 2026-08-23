@php
    // Resultado de la última acción; se cierra solo a los pocos segundos.
    $exito = session('exito');
    $error = session('error');
    $errores = $errors->any();
@endphp

@if ($exito || $error)
    <div x-data="{ visible: true }" x-show="visible" x-transition
        x-init="setTimeout(() => visible = false, 6000)" class="mb-6">
        <x-ui.alert :variant="$error ? 'error' : 'success'"
            :title="$error ? 'No se pudo completar' : 'Listo'"
            :message="$error ?: $exito" />
    </div>
@endif

@if ($errores && ! $error)
    <div class="mb-6">
        <x-ui.alert variant="error" title="Revisa los datos ingresados">
            <ul class="mt-2 space-y-1 text-sm text-gray-500 dark:text-gray-400">
                @foreach ($errors->all() as $mensaje)
                    <li>· {{ $mensaje }}</li>
                @endforeach
            </ul>
        </x-ui.alert>
    </div>
@endif
