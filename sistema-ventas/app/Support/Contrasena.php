<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Cómo tiene que ser una contraseña del sistema.
 *
 * Ocho caracteres con «12345678» adentro no protegen una caja registradora.
 * Se pide un largo mínimo y se rechaza lo que cualquiera probaría primero: el
 * nombre del usuario, el nombre del negocio y las claves de siempre.
 */
class Contrasena
{
    public const MINIMO = 10;

    /** Lo que, estando adentro de la clave, la vuelve adivinable. */
    private const OBVIAS = [
        'password', 'passw0rd', 'contrasena', 'administrador', 'bienvenid',
        '12345678', '87654321', '00000000', 'qwerty', 'abcdefg', 'admin123',
    ];

    /**
     * Las reglas de validación de una contraseña nueva.
     *
     * @param  string|null  $usuario  el nombre de la cuenta a la que se le pone
     * @return array<int, mixed>
     */
    public static function reglas(?string $usuario = null): array
    {
        return [
            Password::min(self::MINIMO),
            fn (string $atributo, mixed $valor, Closure $fallar) => ($problema = self::problema((string) $valor, $usuario)) ? $fallar($problema) : null,
        ];
    }

    /** Por qué una clave no sirve, o null si sirve. */
    public static function problema(string $clave, ?string $usuario = null): ?string
    {
        $clave = self::simplificar($clave);

        $usuario = self::simplificar((string) $usuario);
        if (mb_strlen($usuario) >= 3 && str_contains($clave, $usuario)) {
            return 'La contraseña no puede llevar el nombre de usuario.';
        }

        $negocio = self::simplificar((string) Config::negocio());
        if (mb_strlen($negocio) >= 4 && str_contains($clave, $negocio)) {
            return 'La contraseña no puede llevar el nombre del negocio.';
        }

        foreach (self::OBVIAS as $obvia) {
            if (str_contains($clave, $obvia)) {
                return 'La contraseña es de las primeras que alguien probaría. Usa otra.';
            }
        }

        // «aaaaaaaaaa», «1212121212»: muchos caracteres pero casi ninguno distinto.
        if (count(array_unique(mb_str_split($clave))) <= 3) {
            return 'La contraseña es demasiado simple: repite los mismos caracteres.';
        }

        return null;
    }

    /** Sin mayúsculas, acentos ni signos: «Mi Tienda S.R.L.» ≈ «mitiendasrl». */
    private static function simplificar(string $texto): string
    {
        return preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii($texto))) ?? '';
    }

    /**
     * La contraseña de la primera instalación quedó escrita en un archivo para
     * poder entrar; cuando el administrador pone una propia, ese archivo ya no
     * sirve de nada y solo deja una clave vieja a la vista de quien mire el servidor.
     */
    public static function olvidarPrimerAcceso(): bool
    {
        $ruta = storage_path('app/respaldos/PRIMER-ACCESO.txt');

        return is_file($ruta) && @unlink($ruta);
    }
}
