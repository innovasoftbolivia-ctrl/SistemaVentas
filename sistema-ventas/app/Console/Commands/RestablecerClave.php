<?php

namespace App\Console\Commands;

use App\Models\Usuario;
use App\Services\Auditor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Devuelve el acceso a una cuenta cuando nadie puede entrar para arreglarlo.
 *
 * El caso real: el dueño es el único administrador y olvida su contraseña.
 * El sistema no manda correos ni tiene «olvidé mi contraseña» (a propósito: ese
 * camino es una puerta que alguien más puede abrir), así que la única otra
 * salida era entrar a MySQL y pegar un hash a mano.
 *
 * Quien corre esto ya está dentro del servidor, que es la prueba de que le
 * corresponde. Por eso es un comando y no una pantalla.
 *
 *     docker compose -f docker-compose.prod.yml exec app php artisan usuario:clave admin
 *
 * La clave nueva es temporal: el sistema obliga a cambiarla al entrar. Se
 * muestra en ESTA pantalla y no en ningún archivo ni en el log. No existe una
 * opción para pasarla en la línea de comandos, porque quedaría en el historial
 * de la consola: si hace falta una concreta, `--pedir` la pide sin mostrarla.
 */
class RestablecerClave extends Command
{
    protected $signature = 'usuario:clave
        {usuario : El nombre de usuario, por ejemplo admin}
        {--pedir : Pedir la clave nueva (sin mostrarla) en vez de generar una al azar}
        {--activar : Activar la cuenta si estaba desactivada}';

    protected $description = 'Pone una contraseña temporal a una cuenta (recuperar el acceso del administrador)';

    public function handle(): int
    {
        $nombre = (string) $this->argument('usuario');
        $cuenta = Usuario::with(['empleado', 'rol'])->where('usuario', $nombre)->first();

        if (! $cuenta) {
            $this->error("No existe la cuenta «{$nombre}».");
            $existentes = Usuario::orderBy('usuario')->pluck('usuario')->all();

            if ($existentes !== []) {
                $this->line('Cuentas que existen: '.implode(', ', $existentes));
            }

            return self::FAILURE;
        }

        if ($this->option('pedir')) {
            $clave = (string) $this->secret('Contraseña nueva');
            $confirmacion = (string) $this->secret('Repítela');

            if ($clave !== $confirmacion) {
                $this->error('No coinciden. No se cambió nada.');

                return self::FAILURE;
            }

            $validacion = Validator::make(['clave' => $clave], ['clave' => [Password::min(8)]]);

            if ($validacion->fails()) {
                $this->error($validacion->errors()->first('clave'));

                return self::FAILURE;
            }
        } else {
            $clave = Str::password(14, symbols: false);
        }

        $cuenta->forceFill([
            'password_hash' => Hash::make($clave),
            // Temporal: al entrar, el sistema obliga a poner una propia.
            'debe_cambiar_password' => true,
            'password_actualizado_en' => now(),
            'intentos_fallidos' => 0,
        ]);

        if ($this->option('activar')) {
            $cuenta->activo = true;
        }

        $cuenta->save();

        // Un bloqueo por intentos fallidos no puede dejar fuera a quien acaba de
        // recuperar su clave. El de la dirección IP se vence solo en minutos.
        RateLimiter::clear('login-cuenta:'.mb_strtolower($cuenta->usuario));

        // Las sesiones abiertas con la clave anterior dejan de valer solas: el
        // middleware `auth.session` compara contra el hash de la contraseña.
        Auditor::registrar('CLAVE_RESTABLECIDA_POR_CONSOLA', 'usuarios', $cuenta->id, [
            'usuario' => $cuenta->usuario,
            'generada' => ! $this->option('pedir'),
            'activada' => (bool) $this->option('activar'),
        ]);

        $this->newLine();
        $this->warn(str_repeat('=', 64));
        $this->warn("  Cuenta: {$cuenta->usuario} ({$cuenta->nombre_completo})");

        if (! $this->option('pedir')) {
            $this->warn("  Contraseña temporal: {$clave}");
        } else {
            $this->warn('  La contraseña que escribiste ya está puesta.');
        }

        $this->warn('  Al entrar, el sistema pedirá cambiarla.');
        $this->warn('  No queda en ningún archivo ni en el log: anótala ahora.');
        $this->warn(str_repeat('=', 64));

        if (! $cuenta->puedeIngresar()) {
            $this->newLine();
            $this->error('Ojo: con esta cuenta todavía NO se puede entrar.');

            if (! $cuenta->activo) {
                $this->line('  - La cuenta está desactivada. Vuelve a correr con --activar.');
            }

            if ($cuenta->empleado?->estado !== 'ACTIVO') {
                $this->line('  - El empleado de esta cuenta está dado de baja (cese).');
            }

            if (! $cuenta->rol?->activo) {
                $this->line('  - El rol «'.($cuenta->rol?->nombre ?? 'sin rol').'» está desactivado.');
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
