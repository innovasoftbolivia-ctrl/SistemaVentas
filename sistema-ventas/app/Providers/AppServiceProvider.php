<?php

namespace App\Providers;

use App\Support\Menu;
use Illuminate\Support\Facades\Blade;
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
    }
}
