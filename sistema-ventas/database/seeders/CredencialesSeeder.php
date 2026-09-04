<?php

namespace Database\Seeders;

use App\Models\Usuario;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * El script docs/sql/02_datos_iniciales.sql deja un hash de ejemplo que no
 * corresponde a ninguna contraseña real. Este seeder pone contraseñas
 * utilizables para el entorno de desarrollo, y también es lo que deja una
 * instalación real recién levantada con un primer acceso funcionando.
 *
 * El `entrypoint` de Docker lo corre en CADA arranque del contenedor —no solo
 * la primera vez—, así que solo toca cuentas que nunca tuvieron una
 * contraseña real puesta (`password_actualizado_en IS NULL`). Sin esta
 * condición, reiniciar el contenedor —un reinicio del servidor, una
 * actualización— pisaba en silencio la contraseña que el negocio ya hubiera
 * cambiado, dejando la cuenta otra vez con la clave de fábrica.
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
            $cuenta = Usuario::where('usuario', $usuario)
                ->whereNull('password_actualizado_en')
                ->first();

            if (! $cuenta) {
                $this->command->warn("«{$usuario}» no existe o ya tiene una contraseña propia; se omite.");

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
