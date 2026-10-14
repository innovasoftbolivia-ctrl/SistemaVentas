<?php

namespace App\Providers;

use App\Listeners\ComprobarBaseDeDatos;
use App\Support\Config;
use App\Support\Menu;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // @puede('usuarios.gestionar') ... @endpuede
        Blade::if('puede', fn (string $codigo) => Menu::puede($codigo));

        // `@facturacion … @else … @endfacturacion`: lo que solo se ve cuando el
        // negocio factura (config/ventas.php, `mostrar_facturacion`).
        Blade::if('facturacion', fn () => Config::facturacionVisible());

        // El número de envío único de un formulario (ver UnSoloEnvio).
        Blade::directive('unEnvio', fn () => '<input type="hidden" name="_envio" value="<?php echo e(\Illuminate\Support\Str::uuid()); ?>">');

        // Registrado a mano y no por descubrimiento automático: así queda a
        // la vista que `/up` comprueba la base, que es lo que le da sentido
        // al monitoreo (ver el docblock del listener).
        Event::listen(DiagnosingHealth::class, ComprobarBaseDeDatos::class);

        // El freno de `/up` con su PROPIO cupo: un `throttle:60,1` a secas cuenta
        // en el mismo contador por dirección que el del ingreso y el del mostrador,
        // y un monitoreo insistente dejaría a los cajeros fuera con un 429.
        RateLimiter::for('salud', fn (Request $request) => Limit::perMinute(60)->by('salud:'.$request->ip()));
    }
}
