<?php

namespace App\Services;

use App\Models\Auditoria;
use App\Models\Cargo;
use App\Models\Categoria;
use App\Models\Proveedor;
use App\Models\Rol;
use App\Models\UnidadMedida;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request as Solicitud;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Punto único para dejar rastro de las operaciones sensibles (RNF6).
 *
 * Además de «quién hizo qué», deja QUÉ CAMBIÓ: `foto()` antes de editar y
 * `diferencias()` después dan, campo por campo, el valor de antes y el de
 * después. «Producto actualizado» a secas no sirve cuando hay que saber si el
 * que cambió fue el precio, el proveedor o el estado.
 */
class Auditor
{
    /** Lo que nunca es un cambio que valga la pena anotar. */
    private const IGNORAR = ['actualizado_en', 'creado_en', 'updated_at', 'created_at', 'remember_token', 'stock_actual'];

    /** Campos que se anotan como «cambió», sin guardar ni su valor viejo ni el nuevo. */
    private const SECRETOS = ['password', 'password_hash'];

    /** Un id de otra tabla se anota con su nombre: «Bebidas», no «3». */
    private const NOMBRES = [
        'categoria_id' => [Categoria::class, 'nombre'],
        'unidad_medida_id' => [UnidadMedida::class, 'nombre'],
        'proveedor_id' => [Proveedor::class, 'razon_social'],
        'rol_id' => [Rol::class, 'nombre'],
        'cargo_id' => [Cargo::class, 'nombre'],
    ];

    /** Segundos durante los que un acceso denegado igual (misma persona, misma ruta) no se vuelve a anotar. */
    private const FRENO_DENEGADOS = 60;

    public static function registrar(
        string $accion,
        ?string $entidad = null,
        int|string|null $entidadId = null,
        ?array $detalle = null,
        ?int $usuarioId = null,
    ): void {
        Auditoria::create([
            'usuario_id' => $usuarioId ?? Auth::id(),
            'accion' => $accion,
            'entidad' => $entidad,
            'entidad_id' => $entidadId,
            'detalle' => $detalle,
            'ip' => Request::ip(),
            'fecha' => now(),
        ]);
    }

    /**
     * Cómo está un registro ANTES de editarlo. Se lee de la base (no de lo que
     * trae el formulario) para que los dos lados de la comparación tengan el
     * mismo formato.
     *
     * @return array<string, mixed>
     */
    public static function foto(Model $modelo): array
    {
        $fila = $modelo->newQuery()->whereKey($modelo->getKey())->toBase()->first();

        return $fila ? (array) $fila : [];
    }

    /**
     * Lo que cambió desde la `foto()`: `['precio compra' => ['antes' => '4.00', 'despues' => '5.00']]`.
     * Solo lo que de verdad cambió; vacío si se guardó sin tocar nada.
     *
     * @param  array<string, mixed>  $antes
     * @param  array<int, string>  $ignorar  campos que se dejan fuera además de los de siempre
     * @return array<string, array{antes: mixed, despues: mixed}>
     */
    public static function diferencias(array $antes, Model $modelo, array $ignorar = []): array
    {
        $despues = $modelo->newQuery()->whereKey($modelo->getKey())->toBase()->first();
        $despues = $despues ? (array) $despues : [];
        $fuera = [...self::IGNORAR, ...$ignorar];
        $cambios = [];

        foreach ($despues as $campo => $valor) {
            if (in_array($campo, $fuera, true) || ! array_key_exists($campo, $antes)) {
                continue;
            }

            if (self::normalizar($antes[$campo]) === self::normalizar($valor)) {
                continue;
            }

            if (in_array($campo, self::SECRETOS, true)) {
                $cambios[self::etiqueta($campo)] = ['antes' => '(oculta)', 'despues' => 'cambiada'];

                continue;
            }

            $cambios[self::etiqueta($campo)] = [
                'antes' => self::mostrar($campo, $antes[$campo]),
                'despues' => self::mostrar($campo, $valor),
            ];
        }

        return $cambios;
    }

    /** Antes y después salen de la base con el mismo formato; solo hay que tratar igual el NULL y el vacío. */
    private static function normalizar(mixed $valor): string
    {
        return $valor === null ? '' : (string) $valor;
    }

    private static function etiqueta(string $campo): string
    {
        return str_replace('_', ' ', preg_replace('/_id$/', '', $campo));
    }

    private static function mostrar(string $campo, mixed $valor): mixed
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if (isset(self::NOMBRES[$campo])) {
            [$clase, $columna] = self::NOMBRES[$campo];

            return $clase::whereKey($valor)->value($columna) ?? $valor;
        }

        return is_string($valor) ? Str::limit($valor, 200, '…') : $valor;
    }

    /**
     * Alguien pidió algo que su rol no le permite: queda anotado. Un cajero que
     * prueba direcciones de pantallas que no le tocan es una señal que de otro
     * modo se pierde (el sistema contesta «no tienes acceso» y ahí termina).
     *
     * Con freno: la misma persona pidiendo lo mismo muchas veces se anota una
     * vez por minuto, para que repetir una dirección no llene la tabla.
     */
    public static function accesoDenegado(Solicitud $solicitud, HttpException $error): void
    {
        $usuarioId = Auth::id();

        if (! $usuarioId || $error->getStatusCode() !== 403) {
            return;
        }

        $ruta = $solicitud->route()?->getName() ?? $solicitud->path();

        if (! Cache::add("acceso-denegado:{$usuarioId}:{$solicitud->method()}:{$ruta}", 1, self::FRENO_DENEGADOS)) {
            return;
        }

        try {
            self::registrar('ACCESO_DENEGADO', null, null, [
                'ruta' => '/'.ltrim($solicitud->path(), '/'),
                'metodo' => $solicitud->method(),
                'motivo' => $error->getMessage() ?: 'Sin permiso',
            ], $usuarioId);
        } catch (Throwable $e) {
            // Anotar un acceso denegado nunca debe cambiar la respuesta.
            report($e);
        }
    }
}
