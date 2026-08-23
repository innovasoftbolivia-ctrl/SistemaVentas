import './bootstrap';

import Alpine from 'alpinejs';
import flatpickr from 'flatpickr';
import { Spanish } from 'flatpickr/dist/l10n/es.js';
import 'flatpickr/dist/flatpickr.min.css';

// ApexCharts es pesado: `graficos.js` lo importa bajo demanda y solo en las
// pantallas que declaran algún `[data-apexchart]`.
import { iniciarGraficos } from './graficos';

window.Alpine = Alpine;
window.flatpickr = flatpickr;

flatpickr.localize(Spanish);

Alpine.start();

function iniciarPantalla() {
    iniciarGraficos();

    // Campos de fecha con el calendario de la plantilla.
    document.querySelectorAll('[data-flatpickr]').forEach((input) => {
        flatpickr(input, {
            dateFormat: 'Y-m-d',
            altInput: true,
            altFormat: 'd/m/Y',
            allowInput: true,
        });
    });
}

// Si el documento ya terminó de cargar, `DOMContentLoaded` no volverá a
// dispararse y la pantalla se quedaría sin gráficos ni calendarios.
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', iniciarPantalla);
} else {
    iniciarPantalla();
}
