<?php

namespace Database\Seeders;

use App\Models\Usuario;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * El script docs/sql/02_datos_iniciales.sql deja un hash de ejemplo que no
 * corresponde a ninguna contraseña real. Este seeder pone contraseñas
 * utilizables para el entorno de desarrollo.
 *
 *     php artisan db:seed --class=CredencialesSeeder
 */
class CredencialesSeeder extends Seeder
{
    /** usuario => contraseña de desarrollo */
    private const CLAVES = [
        'admin' => 'admin123',
        'cajero1' => 'cajero123',
        'almacen' => 'almacen123',
    ];

    public function run(): void
    {
        foreach (self::CLAVES as $usuario => $clave) {
            $cuenta = Usuario::where('usuario', $usuario)->first();

            if (! $cuenta) {
                $this->command->warn("No existe la cuenta «{$usuario}»; se omite.");

                continue;
            }

            $cuenta->forceFill([
                'password_hash' => Hash::make($clave),
                'password_actualizado_en' => now(),
                'intentos_fallidos' => 0,
            ])->save();

            $this->command->info("Contraseña establecida para «{$usuario}».");
        }
    }
}
