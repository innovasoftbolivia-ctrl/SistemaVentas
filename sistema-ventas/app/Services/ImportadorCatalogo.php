<?php

namespace App\Services;

use App\Models\Categoria;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\UnidadMedida;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * Carga el catálogo inicial de un negocio desde una hoja de cálculo.
 *
 * Un minimarket real tiene entre 1.000 y 3.000 artículos; tecleados uno por
 * uno en el formulario son días de trabajo y, peor, días de errores. Esto lee un
 * CSV (o un .xlsx) con una fila por producto, revisa TODAS las filas contra las
 * mismas reglas del formulario y solo si ninguna tiene problema carga todo, en
 * una sola transacción: o entra el catálogo entero o no entra nada.
 *
 * Reglas que no son obvias:
 *
 *  - Por omisión solo REVISA (`$aplicar = false`). Cargar es una decisión
 *    explícita: un archivo mal armado no debe ensuciar la base.
 *  - El stock inicial nunca se escribe directo: entra por
 *    {@see Inventario::cargaInicial()}, igual que el alta manual, para que cada
 *    producto quede con su movimiento en el kardex y su lote si vence.
 *  - Las categorías y los proveedores NO se inventan en silencio: un error de
 *    tipeo («Bebidas» / «Bebidas ») crearía una categoría duplicada que nadie
 *    notaría. Se reconocen sin distinguir mayúsculas ni espacios sobrantes; si
 *    no existen, es un error de la fila, salvo que se pida crearlas.
 *  - Un código de barras o código interno repetido, dentro del archivo o contra
 *    lo que ya hay en la base, es un error: no se pisa un producto existente.
 */
class ImportadorCatalogo
{
    /** Encabezados que se aceptan, ya normalizados (minúsculas, sin acentos ni espacios). */
    private const ALIAS = [
        'codigo' => ['codigo', 'codigointerno', 'cod'],
        'codigo_barras' => ['codigobarras', 'codigodebarras', 'barras', 'ean', 'codbarras'],
        'nombre' => ['nombre', 'producto', 'descripcion', 'articulo'],
        'categoria' => ['categoria', 'rubro', 'linea'],
        'unidad' => ['unidad', 'unidadmedida', 'unidaddemedida', 'um'],
        'precio_compra' => ['preciocompra', 'preciodecompra', 'costo', 'compra'],
        'precio_venta' => ['precioventa', 'preciodeventa', 'precio', 'venta'],
        'stock' => ['stock', 'stockinicial', 'existencia', 'cantidad'],
        'stock_minimo' => ['stockminimo', 'stockmin', 'minimo'],
        'proveedor' => ['proveedor'],
        'afecto_impuesto' => ['afectoimpuesto', 'impuesto', 'iva', 'afectoiva'],
        'controla_vencimiento' => ['controlavencimiento', 'vence', 'perecedero', 'vencimiento'],
        'vence' => ['fechavencimiento', 'fechavence', 'venceel'],
        'nombre_empaque' => ['nombreempaque', 'empaque'],
        'contenido_empaque' => ['contenidoempaque', 'unidadesporempaque', 'porempaque'],
    ];

    /** Lo mínimo sin lo cual una fila no se puede cargar. */
    private const OBLIGATORIAS = ['nombre', 'categoria', 'unidad', 'precio_compra', 'precio_venta'];

    /** Tope de filas por archivo: más que esto es un archivo equivocado, no un catálogo. */
    public const MAX_FILAS = 20000;

    /** @var array<string, int> */
    private array $categorias = [];

    /** @var array<string, int> */
    private array $proveedores = [];

    /** @var array<string, array{id: int, decimal: bool}> */
    private array $unidades = [];

    /** @var array<string, true> */
    private array $codigosEnBase = [];

    /** @var array<string, true> */
    private array $barrasEnBase = [];

    /**
     * @param  bool  $aplicar  false = solo revisar
     * @param  bool  $crearCategorias  crear las categorías y proveedores que no existan
     * @return array{filas: int, validas: int, errores: list<array{fila: int, mensaje: string}>, aplicado: bool, creados: int, categorias_nuevas: list<string>, proveedores_nuevos: list<string>, con_stock: int}
     */
    public function importar(string $ruta, bool $aplicar = false, bool $crearCategorias = false): array
    {
        $filas = $this->leer($ruta);

        $this->cargarReferencias();

        $errores = [];
        $validas = [];
        $visto = ['codigo' => [], 'codigo_barras' => []];
        $categoriasNuevas = [];
        $proveedoresNuevos = [];

        foreach ($filas as $numero => $fila) {
            [$datos, $problemas] = $this->prepararFila($fila, $crearCategorias, $categoriasNuevas, $proveedoresNuevos);

            foreach (['codigo' => 'código', 'codigo_barras' => 'código de barras'] as $campo => $etiqueta) {
                $valor = $datos[$campo] ?? null;

                if ($valor === null) {
                    continue;
                }

                if (isset($visto[$campo][$valor])) {
                    $problemas[] = "El {$etiqueta} «{$valor}» ya está en la fila {$visto[$campo][$valor]} de este archivo.";
                } else {
                    $visto[$campo][$valor] = $numero;
                }
            }

            if ($problemas) {
                foreach ($problemas as $mensaje) {
                    $errores[] = ['fila' => $numero, 'mensaje' => $mensaje];
                }

                continue;
            }

            $validas[$numero] = $datos;
        }

        $resultado = [
            'filas' => count($filas),
            'validas' => count($validas),
            'errores' => $errores,
            'aplicado' => false,
            'creados' => 0,
            'categorias_nuevas' => array_values(array_unique($categoriasNuevas)),
            'proveedores_nuevos' => array_values(array_unique($proveedoresNuevos)),
            'con_stock' => count(array_filter($validas, fn ($d) => $d['stock'] > 0)),
        ];

        // Todo o nada: con una sola fila mala no se carga ninguna.
        if (! $aplicar || $errores !== [] || $validas === []) {
            return $resultado;
        }

        DB::transaction(function () use ($validas, &$resultado) {
            $siguiente = $this->siguienteNumeroDeCodigo();

            foreach ($validas as $datos) {
                $resultado['creados'] += $this->crear($datos, $siguiente) ? 1 : 0;
            }
        });

        $resultado['aplicado'] = true;

        Auditor::registrar('CATALOGO_IMPORTADO', 'productos', null, [
            'archivo' => basename($ruta),
            'productos' => $resultado['creados'],
            'con_stock' => $resultado['con_stock'],
            'categorias_nuevas' => $resultado['categorias_nuevas'],
        ]);

        return $resultado;
    }

    /**
     * La plantilla que se le entrega al cliente: encabezados y dos filas de ejemplo.
     */
    public static function plantilla(): string
    {
        $cabecera = ['codigo_barras', 'nombre', 'categoria', 'unidad', 'precio_compra', 'precio_venta', 'stock', 'stock_minimo', 'proveedor', 'afecto_impuesto', 'controla_vencimiento', 'fecha_vencimiento', 'codigo', 'nombre_empaque', 'contenido_empaque'];
        $ejemplos = [
            ['7771234567890', 'Leche entera 1 L', 'Lácteos', 'UND', '6.50', '8.00', '24', '6', '', 'si', 'si', date('Y-m-d', strtotime('+90 days')), '', 'Caja', '12'],
            ['', 'Arroz grano de oro (a granel)', 'Abarrotes', 'KG', '7.20', '9.00', '35.5', '10', '', 'si', 'no', '', '', '', ''],
        ];

        $salida = "\xEF\xBB\xBF"; // BOM: Excel abre bien los acentos
        foreach (array_merge([$cabecera], $ejemplos) as $linea) {
            $salida .= implode(';', array_map(fn ($c) => str_contains($c, ';') ? '"'.$c.'"' : $c, $linea))."\r\n";
        }

        return $salida;
    }

    // ------------------------------------------------------------------ lectura

    /**
     * Las filas como arreglos por clave interna, numeradas como las ve el
     * usuario en su hoja (la fila 1 es el encabezado).
     *
     * @return array<int, array<string, string>>
     */
    private function leer(string $ruta): array
    {
        if (! is_file($ruta) || ! is_readable($ruta)) {
            throw new RuntimeException("No encuentro el archivo «{$ruta}».");
        }

        $extension = strtolower(pathinfo($ruta, PATHINFO_EXTENSION));

        $matriz = match ($extension) {
            'csv', 'txt' => $this->leerCsv($ruta),
            'xlsx', 'xls' => $this->leerHoja($ruta),
            default => throw new RuntimeException('El archivo tiene que ser .csv o .xlsx.'),
        };

        if (count($matriz) < 2) {
            throw new RuntimeException('El archivo no tiene productos: solo el encabezado o nada.');
        }

        if (count($matriz) - 1 > self::MAX_FILAS) {
            throw new RuntimeException('El archivo tiene más de '.self::MAX_FILAS.' filas: revisa que sea el correcto.');
        }

        $columnas = $this->mapearColumnas(array_shift($matriz));

        $filas = [];
        foreach ($matriz as $i => $celdas) {
            $numero = $i + 2;
            $fila = [];

            foreach ($columnas as $posicion => $campo) {
                $fila[$campo] = trim((string) ($celdas[$posicion] ?? ''));
            }

            // Las filas totalmente vacías (típicas al final de un Excel) se saltan.
            if (implode('', $fila) === '') {
                continue;
            }

            $filas[$numero] = $fila;
        }

        return $filas;
    }

    /** @return list<list<string>> */
    private function leerCsv(string $ruta): array
    {
        $texto = (string) file_get_contents($ruta);
        $texto = preg_replace('/^\xEF\xBB\xBF/', '', $texto);

        // Excel en español guarda con «;»; en inglés, con «,». Se decide por la
        // primera línea, la del encabezado.
        $primera = strtok($texto, "\n") ?: '';
        $delimitador = substr_count($primera, ';') >= substr_count($primera, ',') ? ';' : ',';

        // Un CSV de Excel en Windows suele venir en Windows-1252, no en UTF-8.
        if (! mb_check_encoding($texto, 'UTF-8')) {
            $texto = mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');
        }

        $flujo = fopen('php://memory', 'r+');
        fwrite($flujo, $texto);
        rewind($flujo);

        $matriz = [];
        while (($linea = fgetcsv($flujo, 0, $delimitador, '"', '')) !== false) {
            $matriz[] = array_map(fn ($c) => (string) $c, $linea);
        }
        fclose($flujo);

        return $matriz;
    }

    /** @return list<list<string>> */
    private function leerHoja(string $ruta): array
    {
        $hoja = IOFactory::load($ruta)->getActiveSheet();
        $matriz = [];

        foreach ($hoja->toArray(null, true, true, false) as $linea) {
            $matriz[] = array_map(fn ($c) => is_scalar($c) ? (string) $c : '', $linea);
        }

        return $matriz;
    }

    /**
     * @param  list<string>  $encabezado
     * @return array<int, string> posición de la columna => campo interno
     */
    private function mapearColumnas(array $encabezado): array
    {
        $columnas = [];

        foreach ($encabezado as $posicion => $titulo) {
            $clave = $this->normalizar($titulo);

            foreach (self::ALIAS as $campo => $alias) {
                if (in_array($clave, $alias, true) && ! in_array($campo, $columnas, true)) {
                    $columnas[$posicion] = $campo;
                    break;
                }
            }
        }

        $faltan = array_diff(self::OBLIGATORIAS, $columnas);

        if ($faltan !== []) {
            throw new RuntimeException('Al archivo le faltan columnas: '.implode(', ', $faltan).'. Descarga la plantilla con «php artisan catalogo:importar --plantilla».');
        }

        return $columnas;
    }

    private function normalizar(string $texto): string
    {
        $texto = preg_replace('/^\xEF\xBB\xBF/', '', $texto);
        $texto = mb_strtolower(trim($texto));
        $texto = strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return preg_replace('/[^a-z0-9]/', '', $texto);
    }

    // ------------------------------------------------------------------ revisión

    private function cargarReferencias(): void
    {
        $this->categorias = Categoria::activas()->get(['id', 'nombre'])
            ->mapWithKeys(fn ($c) => [$this->clave($c->nombre) => $c->id])->all();

        $this->proveedores = Proveedor::activos()->get(['id', 'razon_social'])
            ->mapWithKeys(fn ($p) => [$this->clave($p->razon_social) => $p->id])->all();

        $this->unidades = UnidadMedida::all()
            ->mapWithKeys(fn ($u) => [$this->clave($u->codigo) => ['id' => $u->id, 'decimal' => (bool) $u->permite_decimal]])->all();

        $this->codigosEnBase = Producto::query()->pluck('codigo')->mapWithKeys(fn ($c) => [(string) $c => true])->all();
        $this->barrasEnBase = Producto::query()->whereNotNull('codigo_barras')->pluck('codigo_barras')
            ->mapWithKeys(fn ($c) => [(string) $c => true])->all();
    }

    private function clave(string $texto): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim($texto)));
    }

    /**
     * @param  array<string, string>  $fila
     * @param  list<string>  $categoriasNuevas
     * @param  list<string>  $proveedoresNuevos
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function prepararFila(array $fila, bool $crearCategorias, array &$categoriasNuevas, array &$proveedoresNuevos): array
    {
        $problemas = [];

        // Los números aceptan coma o punto decimal.
        $numero = fn (string $v): ?string => $v === '' ? null : str_replace(',', '.', preg_replace('/\s+/', '', $v));

        $unidad = $this->unidades[$this->clave($fila['unidad'] ?? '')] ?? null;
        if (! $unidad) {
            $problemas[] = 'La unidad «'.($fila['unidad'] ?? '').'» no existe. Usa el código: '.implode(', ', array_map('strtoupper', array_keys($this->unidades))).'.';
        }

        $nombreCategoria = trim($fila['categoria'] ?? '');
        $categoriaId = $this->categorias[$this->clave($nombreCategoria)] ?? null;
        if (! $categoriaId) {
            if ($crearCategorias && $nombreCategoria !== '') {
                $categoriasNuevas[] = $nombreCategoria;
            } else {
                $problemas[] = "La categoría «{$nombreCategoria}» no existe.";
            }
        }

        $nombreProveedor = trim($fila['proveedor'] ?? '');
        $proveedorId = null;
        if ($nombreProveedor !== '') {
            $proveedorId = $this->proveedores[$this->clave($nombreProveedor)] ?? null;
            if (! $proveedorId) {
                if ($crearCategorias) {
                    $proveedoresNuevos[] = $nombreProveedor;
                } else {
                    $problemas[] = "El proveedor «{$nombreProveedor}» no existe.";
                }
            }
        }

        $si = fn (string $v, bool $porOmision): bool => $v === '' ? $porOmision : in_array($this->normalizar($v), ['si', 's', '1', 'true', 'x', 'yes', 'y'], true);

        $datos = [
            'codigo' => ($fila['codigo'] ?? '') !== '' ? $fila['codigo'] : null,
            // Excel convierte un EAN largo en «7.77123E+12» o le quita ceros: se avisa en vez de adivinar.
            'codigo_barras' => ($fila['codigo_barras'] ?? '') !== '' ? $fila['codigo_barras'] : null,
            'nombre' => preg_replace('/\s+/', ' ', trim($fila['nombre'] ?? '')),
            'descripcion' => null,
            'categoria_id' => $categoriaId,
            'unidad_medida_id' => $unidad['id'] ?? null,
            'proveedor_id' => $proveedorId,
            'precio_compra' => $numero($fila['precio_compra'] ?? ''),
            'precio_venta' => $numero($fila['precio_venta'] ?? ''),
            'stock_minimo' => $numero($fila['stock_minimo'] ?? '') ?? '0',
            'afecto_impuesto' => $si($fila['afecto_impuesto'] ?? '', true),
            'controla_vencimiento' => $si($fila['controla_vencimiento'] ?? '', false),
            'activo' => true,
            'nombre_empaque' => ($fila['nombre_empaque'] ?? '') !== '' ? $fila['nombre_empaque'] : null,
            'contenido_empaque' => $numero($fila['contenido_empaque'] ?? ''),
            'stock' => (float) ($numero($fila['stock'] ?? '') ?? 0),
            'vence' => ($fila['vence'] ?? '') !== '' ? $this->fecha($fila['vence']) : null,
            '_nombre_categoria' => $nombreCategoria,
            '_nombre_proveedor' => $nombreProveedor,
        ];

        $validador = Validator::make($datos, [
            'nombre' => ['required', 'string', 'max:120'],
            'codigo' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/'],
            'codigo_barras' => ['nullable', 'string', 'max:50', 'regex:/^[0-9]+$/'],
            'precio_compra' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'precio_venta' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'stock_minimo' => ['numeric', 'min:0', 'max:999999'],
            'stock' => ['numeric', 'min:0', 'max:999999'],
            'nombre_empaque' => ['nullable', 'string', 'min:2', 'max:20', 'required_with:contenido_empaque'],
            'contenido_empaque' => ['nullable', 'numeric', 'gt:1', 'max:999999', 'required_with:nombre_empaque'],
        ], [
            'codigo.regex' => 'El código admite letras, números, punto, guion y guion bajo.',
            'codigo_barras.regex' => 'El código de barras solo admite dígitos (Excel suele estropear los largos: guarda esa columna como texto).',
            'nombre_empaque.required_with' => 'Falta el nombre del empaque (caja, paquete…).',
            'contenido_empaque.required_with' => 'Falta cuántas unidades trae el empaque.',
            'contenido_empaque.gt' => 'Un empaque de una sola unidad no sirve: deja vacío el empaque.',
        ], [
            'nombre' => 'nombre', 'precio_compra' => 'precio de compra', 'precio_venta' => 'precio de venta',
            'stock_minimo' => 'stock mínimo', 'stock' => 'stock', 'codigo_barras' => 'código de barras',
            'nombre_empaque' => 'nombre del empaque', 'contenido_empaque' => 'contenido del empaque',
        ]);

        foreach ($validador->errors()->all() as $mensaje) {
            $problemas[] = $mensaje;
        }

        if ($datos['codigo'] !== null && isset($this->codigosEnBase[$datos['codigo']])) {
            $problemas[] = "El código «{$datos['codigo']}» ya existe en el sistema.";
        }

        if ($datos['codigo_barras'] !== null && isset($this->barrasEnBase[$datos['codigo_barras']])) {
            $problemas[] = "El código de barras «{$datos['codigo_barras']}» ya está asignado a otro producto.";
        }

        // La unidad decide si el stock admite decimales.
        if ($unidad && ! $unidad['decimal'] && $datos['stock'] != floor($datos['stock'])) {
            $problemas[] = "El stock {$datos['stock']} lleva decimales y la unidad «{$fila['unidad']}» no los admite.";
        }

        if ($datos['stock'] > 0 && $datos['controla_vencimiento'] && ! $datos['vence']) {
            $problemas[] = 'Controla vencimiento y tiene stock: falta la fecha de vencimiento de ese stock.';
        }

        if ($datos['vence'] !== null && $datos['vence'] < date('Y-m-d')) {
            $problemas[] = "La fecha de vencimiento {$datos['vence']} ya pasó.";
        }

        if ($datos['vence'] !== null && ! $datos['controla_vencimiento']) {
            $problemas[] = 'Trae fecha de vencimiento pero no está marcado como que controla vencimiento.';
        }

        // Vender a menos de lo que costó no es un error (hay promociones), pero casi
        // siempre es un dedazo: se detiene para que alguien lo confirme.
        if (is_numeric($datos['precio_compra']) && is_numeric($datos['precio_venta']) && (float) $datos['precio_venta'] < (float) $datos['precio_compra']) {
            $problemas[] = "El precio de venta ({$datos['precio_venta']}) es menor que el de compra ({$datos['precio_compra']}).";
        }

        return [$datos, $problemas];
    }

    /** Acepta 2026-12-31, 31/12/2026 y 31-12-2026, y el número de serie que guarda Excel. */
    private function fecha(string $texto): ?string
    {
        $texto = trim($texto);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $texto, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : '0000-00-00';
        }

        if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/', $texto, $m)) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3])
                ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1])
                : '0000-00-00';
        }

        if (is_numeric($texto) && (float) $texto > 20000) {
            return date('Y-m-d', (int) (((float) $texto - 25569) * 86400));
        }

        return '0000-00-00';
    }

    // ------------------------------------------------------------------ carga

    private function siguienteNumeroDeCodigo(): int
    {
        $ultimo = Producto::where('codigo', 'regexp', '^P-[0-9]+$')
            ->orderByRaw('CAST(SUBSTRING(codigo, 3) AS UNSIGNED) DESC')
            ->value('codigo');

        return $ultimo ? ((int) substr($ultimo, 2)) + 1 : 1;
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function crear(array $datos, int &$siguiente): bool
    {
        if (! $datos['categoria_id']) {
            $datos['categoria_id'] = $this->categoriaNueva($datos['_nombre_categoria']);
        }

        if (! $datos['proveedor_id'] && $datos['_nombre_proveedor'] !== '') {
            $datos['proveedor_id'] = $this->proveedorNuevo($datos['_nombre_proveedor']);
        }

        $codigo = $datos['codigo'];
        if ($codigo === null) {
            do {
                $codigo = 'P-'.str_pad((string) $siguiente++, 4, '0', STR_PAD_LEFT);
            } while (isset($this->codigosEnBase[$codigo]));
        }
        $this->codigosEnBase[$codigo] = true;

        $producto = Producto::create([
            'categoria_id' => $datos['categoria_id'],
            'unidad_medida_id' => $datos['unidad_medida_id'],
            'proveedor_id' => $datos['proveedor_id'],
            'codigo' => $codigo,
            'codigo_barras' => $datos['codigo_barras'],
            'nombre' => $datos['nombre'],
            'precio_compra' => $datos['precio_compra'],
            'precio_venta' => $datos['precio_venta'],
            'afecto_impuesto' => $datos['afecto_impuesto'],
            'controla_vencimiento' => $datos['controla_vencimiento'],
            'stock_minimo' => $datos['stock_minimo'],
            'nombre_empaque' => $datos['nombre_empaque'],
            'contenido_empaque' => $datos['contenido_empaque'],
            'activo' => true,
        ]);

        // El stock entra por el kardex, como en el alta manual.
        Inventario::cargaInicial($producto, (float) $datos['stock'], 'importación de catálogo', $datos['vence']);

        return true;
    }

    /** @var array<string, int> */
    private array $creadas = [];

    private function categoriaNueva(string $nombre): int
    {
        $clave = $this->clave($nombre);

        return $this->creadas['c:'.$clave] ??= Categoria::create(['nombre' => $nombre, 'activo' => true])->id;
    }

    private function proveedorNuevo(string $nombre): int
    {
        $clave = $this->clave($nombre);

        return $this->creadas['p:'.$clave] ??= Proveedor::create(['razon_social' => $nombre, 'activo' => true])->id;
    }
}
