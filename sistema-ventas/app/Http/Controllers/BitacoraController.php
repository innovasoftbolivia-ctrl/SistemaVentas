<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ExportaInformes;
use App\Models\Auditoria;
use App\Models\AuditoriaArchivo;
use App\Models\Usuario;
use App\Services\Archivador;
use App\Support\TopeExcel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * La bitácora de operaciones, para leerla.
 *
 * El sistema ya registraba mucho —ingresos fallidos, bloqueos, altas, bajas,
 * cambios de precio, anulaciones— pero no había pantalla: para saber quién
 * anuló una venta había que entrar a la base. Esto es solo lectura. Nada de lo
 * registrado se puede editar ni borrar desde acá, que es justamente lo que le
 * da valor.
 */
class BitacoraController extends Controller
{
    use ExportaInformes;

    /** A qué pantalla lleva cada entidad registrada, cuando tiene una. */
    private const ENLACES = [
        'ventas' => 'ventas.show',
        'compras' => 'compras.show',
        'devoluciones' => 'devoluciones.show',
        'devoluciones_compra' => 'devoluciones-compra.show',
        'empleados' => 'empleados.show',
        'productos' => 'productos.show',
        'usuarios' => 'usuarios.edit',
        'sesiones_caja' => 'caja.show',
        'comprobantes' => 'comprobantes.imprimir',
        'tomas_inventario' => 'tomas.show',
    ];

    /** El nombre de cada entidad en singular, para leer «Venta #12». */
    private const ENTIDADES = [
        'ventas' => 'Venta',
        'compras' => 'Compra',
        'devoluciones' => 'Devolución',
        'devoluciones_compra' => 'Devolución a proveedor',
        'empleados' => 'Empleado',
        'productos' => 'Producto',
        'usuarios' => 'Usuario',
        'sesiones_caja' => 'Turno de caja',
        'comprobantes' => 'Comprobante',
        'cajas' => 'Caja',
        'cargos' => 'Cargo',
        'categorias' => 'Categoría',
        'clientes' => 'Cliente',
        'proveedores' => 'Proveedor',
        'roles' => 'Rol',
        'unidades_medida' => 'Unidad de medida',
        'cobros_qr' => 'Cobro QR',
        'configuracion' => 'Configuración',
        'reportes' => 'Reporte',
        'tomas_inventario' => 'Toma de inventario',
    ];

    /**
     * Palabras del código de acción que necesitan acento o cambian de nombre.
     * `strtr` reemplaza primero lo más largo, así «login fallido» gana sobre
     * «login».
     */
    private const PALABRAS = [
        'login fallido' => 'ingreso fallido',
        'login bloqueado' => 'ingreso bloqueado',
        'login' => 'ingreso',
        'visto' => 'consultado',
        'logout' => 'salida',
        'password cambiada' => 'contraseña cambiada',
        'anular venta' => 'venta anulada',
        'sustituir comprobante' => 'comprobante sustituido',
        'configuracion' => 'configuración',
        'toma inventario' => 'toma de inventario',
        'devolucion' => 'devolución',
        'categoria' => 'categoría',
        'fisica' => 'física',
        'qr' => 'QR',
    ];

    /**
     * Los filtros de la pantalla, validados: los mismos para leerla y para descargarla.
     *
     * @return array{usuario: ?int, accion: ?string, desde: ?string, hasta: ?string, buscar: string, archivo: bool}
     */
    private function filtros(Request $request): array
    {
        $filtros = $request->validate([
            'usuario' => ['nullable', 'integer'],
            'accion' => ['nullable', 'string', 'max:50'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'buscar' => ['nullable', 'string', 'max:100'],
            'archivo' => ['nullable', 'boolean'],
        ], [
            'hasta.after_or_equal' => 'La fecha «hasta» no puede ser anterior a «desde».',
        ]);

        return [
            'usuario' => $filtros['usuario'] ?? null,
            'accion' => $filtros['accion'] ?? null,
            'desde' => $filtros['desde'] ?? null,
            'hasta' => $filtros['hasta'] ?? null,
            'buscar' => trim((string) ($filtros['buscar'] ?? '')),
            // Lo de más de un año vive en otra tabla (ver Archivador).
            'archivo' => (bool) ($filtros['archivo'] ?? false),
        ];
    }

    /** @param  array{usuario: ?int, accion: ?string, desde: ?string, hasta: ?string, buscar: string, archivo: bool}  $filtros */
    private function consulta(array $filtros): Builder
    {
        $buscar = $filtros['buscar'];

        return ($filtros['archivo'] ? AuditoriaArchivo::query() : Auditoria::query())
            ->with('usuario:id,usuario,empleado_id', 'usuario.empleado:id,nombre_completo')
            ->when($filtros['usuario'], fn ($q, $id) => $q->where('usuario_id', $id))
            ->when($filtros['accion'], fn ($q, $accion) => $q->where('accion', $accion))
            ->when($filtros['desde'], fn ($q, $desde) => $q->where('fecha', '>=', $desde.' 00:00:00'))
            ->when($filtros['hasta'], fn ($q, $hasta) => $q->where('fecha', '<=', $hasta.' 23:59:59'))
            ->when($buscar !== '', function ($q) use ($buscar) {
                $q->where(function ($sub) use ($buscar) {
                    if (ctype_digit($buscar)) {
                        $sub->orWhere('entidad_id', (int) $buscar);
                    }
                    $sub->orWhere('ip', 'like', "%{$buscar}%")
                        // El detalle es JSON: se lo compara como texto. Encuentra
                        // un nombre o un precio que alguien cambió.
                        ->orWhereRaw('CAST(detalle AS CHAR) LIKE ?', ["%{$buscar}%"]);
                });
            })
            ->orderByDesc('fecha')
            ->orderByDesc('id');
    }

    /**
     * La bitácora filtrada, en un Excel: es la que se le entrega a quien audita o
     * se guarda como prueba de un incidente. Con los mismos filtros que la pantalla.
     * Descargarla queda anotado en la propia bitácora (middleware `consulta`).
     */
    public function exportar(Request $request): StreamedResponse|RedirectResponse
    {
        $filtros = $this->filtros($request);

        // Un renglón de más que el tope: alcanza para que `TopeExcel` avise en vez de
        // cargar en memoria una tabla de millones de filas.
        $registros = $this->consulta($filtros)->limit(TopeExcel::maximo() + 1)->get();

        $periodo = match (true) {
            $filtros['desde'] && $filtros['hasta'] => 'Del '.date('d/m/Y', strtotime($filtros['desde'])).' al '.date('d/m/Y', strtotime($filtros['hasta'])),
            (bool) $filtros['desde'] => 'Desde el '.date('d/m/Y', strtotime($filtros['desde'])),
            (bool) $filtros['hasta'] => 'Hasta el '.date('d/m/Y', strtotime($filtros['hasta'])),
            default => 'Toda la bitácora',
        }.($filtros['archivo'] ? ' (archivo)' : '');

        $filas = $registros->map(fn (Auditoria $r) => [
            $r->fecha?->format('d/m/Y H:i:s'),
            $r->usuario?->usuario ?? '—',
            $r->usuario?->empleado?->nombre_completo ?? '',
            $r->ip ?? '',
            self::accion($r->accion),
            self::entidad($r->entidad, $r->entidad_id) ?? '',
            collect(self::detalle($r->detalle))->map(fn ($f) => "{$f[0]}: {$f[1]}")->implode('; '),
        ])->all();

        $documento = $this->envoltorio('Bitácora de operaciones', $periodo, [
            ['etiqueta' => 'Registros', 'valor' => count($filas), 'formato' => 'entero'],
        ], [[
            'nombre' => 'Operaciones',
            'nota' => 'De la más nueva a la más vieja.',
            'cabeceras' => ['Cuándo', 'Usuario', 'Persona', 'IP', 'Qué hizo', 'Sobre qué', 'Detalle'],
            'formatos' => [null, null, null, null, null, null, null],
            'alineacion' => ['izq', 'izq', 'izq', 'izq', 'izq', 'izq', 'izq'],
            'filas' => $filas,
            'vacia' => 'No hay registros con esos criterios.',
        ]]);

        return $this->excel($documento, 'bitacora_'.now()->format('Y-m-d_His').'.xlsx');
    }

    public function index(Request $request): View
    {
        $filtros = $this->filtros($request);
        $buscar = $filtros['buscar'];

        $registros = $this->consulta($filtros)
            ->paginate(50)
            ->withQueryString();

        return view('bitacora.index', [
            'title' => 'Bitácora',
            'registros' => $registros,
            'filtros' => $filtros,
            // Solo lo que de verdad aparece en la bitácora: un desplegable con
            // sesenta acciones de las que la mitad nunca pasó no ayuda a buscar.
            'archivadoHasta' => Archivador::archivadoHasta(),
            'acciones' => ($filtros['archivo'] ? AuditoriaArchivo::query() : Auditoria::query())->distinct()->orderBy('accion')->pluck('accion')
                ->mapWithKeys(fn (string $a) => [$a => self::accion($a)])->all(),
            'usuarios' => Usuario::query()->orderBy('usuario')->pluck('usuario', 'id')->all(),
        ]);
    }

    /** «CONFIGURACION_ACTUALIZADA» → «Configuración actualizada». */
    public static function accion(string $codigo): string
    {
        $texto = strtr(mb_strtolower(str_replace('_', ' ', $codigo)), self::PALABRAS);

        return mb_strtoupper(mb_substr($texto, 0, 1)).mb_substr($texto, 1);
    }

    /** «Venta #12», o el nombre de la tabla si no se conoce. */
    public static function entidad(?string $entidad, int|string|null $id): ?string
    {
        if (! $entidad) {
            return null;
        }

        $nombre = self::ENTIDADES[$entidad] ?? ucfirst(str_replace('_', ' ', $entidad));

        return $id ? "{$nombre} #{$id}" : $nombre;
    }

    /** La pantalla de la entidad, si tiene una y el registro guarda su id. */
    public static function enlace(?string $entidad, int|string|null $id): ?string
    {
        $ruta = self::ENLACES[$entidad] ?? null;

        return $ruta && $id && Route::has($ruta) ? route($ruta, $id) : null;
    }

    /**
     * El detalle, en filas que se leen: los cambios con su valor anterior
     * («3.98 → 4.20») y el resto tal cual.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public static function detalle(?array $detalle): array
    {
        $filas = [];

        foreach ($detalle ?? [] as $clave => $valor) {
            $etiqueta = str_replace('_', ' ', (string) $clave);

            // Lo que cambió, campo por campo: «precio compra: 4 → 5».
            if ($clave === 'cambios' && is_array($valor)) {
                foreach ($valor as $campo => $par) {
                    $filas[] = ['cambió '.$campo, self::valor($par['antes'] ?? null).' → '.self::valor($par['despues'] ?? null)];
                }

                continue;
            }

            // Los filtros con que se miró un reporte: «desde 2026-10-01 · hasta …».
            if ($clave === 'filtros' && is_array($valor)) {
                $filas[] = ['filtros', collect($valor)->map(fn ($v, $k) => "{$k} {$v}")->implode(' · ')];

                continue;
            }

            if (is_array($valor) && array_key_exists('antes', $valor) && array_key_exists('despues', $valor)) {
                $filas[] = [$etiqueta, self::valor($valor['antes']).' → '.self::valor($valor['despues'])];
            } else {
                $filas[] = [$etiqueta, self::valor($valor)];
            }
        }

        return $filas;
    }

    private static function valor(mixed $valor): string
    {
        return match (true) {
            $valor === null, $valor === '' => '—',
            is_bool($valor) => $valor ? 'sí' : 'no',
            is_array($valor) => json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => (string) $valor,
        };
    }
}
