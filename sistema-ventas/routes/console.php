<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// El respaldo de todas las noches. Corre donde haya un programador de tareas
// andando —en Docker de producción, el servicio `programador`—, se vea o no la
// pantalla Sistema > Respaldos (MOSTRAR_RESPALDOS). En un hosting compartido no
// hay programador, y los respaldos se hacen desde esa pantalla.
Schedule::command('respaldo:crear')->dailyAt('01:00')->withoutOverlapping();

// Después del respaldo, la revisión de que todo cuadre: stock contra kardex, kardex contra
// sí mismo, stock contra lotes, ventas contra sus pagos. Un descuadre se descubre la noche
// en que ocurre, no meses después en un inventario. Deja constancia en la bitácora y, si
// encuentra algo, la portada lo avisa a quien gestiona.
Schedule::command('descuadres:revisar')->dailyAt('01:30')->withoutOverlapping();

// Los QR vencidos se cancelan en el banco. Si no, un código que el cajero dejó
// atrás sigue siendo cobrable allá durante horas: el cliente paga, el dinero
// entra a la cuenta y no hay ninguna venta esperándolo.
Schedule::command('qr:vencer')->everyFiveMinutes()->withoutOverlapping();
