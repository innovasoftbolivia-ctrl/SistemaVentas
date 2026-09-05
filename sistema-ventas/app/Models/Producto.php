<?php

namespace App\Models;

use App\Services\Inventario;
use App\Support\Config;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Un artículo del catálogo.
 *
 * Sobre los precios: `precio_compra` y `precio_venta` se guardan SIN impuesto.
 * El precio que ve el cliente en el estante es `precio_venta * (1 + tasa)`, y
 * se calcula al vuelo con la tasa vigente en `configuracion`.
 *
 * Sobre el stock: `stock_actual` NO se edita a mano. Cambia solo a través de
 * {@see Inventario}, que deja siempre un movimiento con su
 * responsable y su motivo.
 */
class Producto extends Model
{
    protected $table = 'productos';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = 'actualizado_en';

    protected $fillable = [
        'categoria_id', 'unidad_medida_id', 'proveedor_id',
        'codigo', 'codigo_barras', 'nombre', 'descripcion',
        'precio_compra', 'precio_venta', 'afecto_impuesto',
        'stock_minimo', 'imagen', 'activo',
    ];

    protected function casts(): array
    {
        return [
            'precio_compra' => 'decimal:2',
            'precio_venta' => 'decimal:2',
            'stock_actual' => 'decimal:3',
            'stock_minimo' => 'decimal:3',
            'afecto_impuesto' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class, 'categoria_id');
    }

    public function unidadMedida(): BelongsTo
    {
        return $this->belongsTo(UnidadMedida::class, 'unidad_medida_id');
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoInventario::class, 'producto_id');
    }

    // ------------------------------------------------------------- consultas

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', 1);
    }

    /** Busca por nombre, código interno o código de barras (RNF2: lector + Enter). */
    public function scopeBuscar(Builder $query, ?string $texto): Builder
    {
        if (blank($texto)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($texto) {
            $q->where('nombre', 'like', "%{$texto}%")
                ->orWhere('codigo', 'like', "%{$texto}%")
                ->orWhere('codigo_barras', 'like', "%{$texto}%");
        });
    }

    /** Productos que llegaron a su stock mínimo (O7: alerta de quiebre). */
    public function scopeBajoMinimo(Builder $query): Builder
    {
        return $query->whereColumn('stock_actual', '<=', 'stock_minimo');
    }

    /**
     * Las mismas filas y columnas que la vista `v_alertas_stock`, como
     * consulta.
     *
     * Era la única vista que la aplicación leía de verdad (las demás se citan
     * en los comentarios como definición de referencia, pero los reportes ya
     * consultan las tablas base). Se reescribió porque un hosting compartido
     * puede denegar `CREATE VIEW` —InfinityFree lo hace, con el error 1142— y
     * sin esto el panel y los reportes se caían enteros.
     *
     * `ReportesTest` compara esta consulta contra la vista del esquema, para
     * que las dos no se separen donde la vista sí existe.
     */
    public static function alertasDeStock(): \Illuminate\Database\Query\Builder
    {
        return DB::table('productos as p')
            ->join('categorias as c', 'c.id', '=', 'p.categoria_id')
            ->where('p.activo', 1)
            ->whereColumn('p.stock_actual', '<=', 'p.stock_minimo')
            ->selectRaw('p.id, p.codigo, p.nombre, c.nombre AS categoria')
            ->selectRaw('p.stock_actual, p.stock_minimo, (p.stock_minimo - p.stock_actual) AS faltante');
    }

    // ------------------------------------------------------------- derivados

    /** Precio que ve el cliente: base + impuesto, si el producto está afecto. */
    public function getPrecioEstanteAttribute(): float
    {
        $base = (float) $this->precio_venta;

        return $this->afecto_impuesto
            ? round($base * (1 + Config::tasaImpuesto()), 2)
            : round($base, 2);
    }

    /** Ganancia por unidad sobre la base imponible. */
    public function getMargenAttribute(): float
    {
        return round((float) $this->precio_venta - (float) $this->precio_compra, 2);
    }

    /** Margen en porcentaje del precio de venta. */
    public function getMargenPorcentajeAttribute(): ?float
    {
        $venta = (float) $this->precio_venta;

        return $venta > 0 ? round($this->margen / $venta * 100, 1) : null;
    }

    public function getSinStockAttribute(): bool
    {
        return (float) $this->stock_actual <= 0;
    }

    public function getBajoMinimoAttribute(): bool
    {
        return (float) $this->stock_actual <= (float) $this->stock_minimo;
    }

    /** Cuánto capital está inmovilizado en este producto. */
    public function getValorInventarioAttribute(): float
    {
        return round((float) $this->stock_actual * (float) $this->precio_compra, 2);
    }

    /**
     * URL pública de la foto, o null si no tiene.
     *
     * En `imagen` se guarda la ruta relativa dentro del disco `public`
     * (`productos/xxx.jpg`), no la URL: así el archivo sigue encontrándose si
     * cambia el dominio o la carpeta desde la que se sirve.
     *
     * Se usa `asset()` y no `Storage::url()` a propósito: este último arma la
     * dirección con `APP_URL`, y el sistema se abre desde varias máquinas de la
     * red del negocio (RNF3). Con `APP_URL=http://localhost` las fotos se
     * romperían en todas menos en el servidor. `asset()` toma el host de la
     * petición en curso, así que la imagen se pide siempre al mismo sitio desde
     * el que se abrió la página.
     */
    public function getImagenUrlAttribute(): ?string
    {
        return $this->imagen ? asset('storage/'.$this->imagen) : null;
    }

    public function tieneImagen(): bool
    {
        return filled($this->imagen) && Storage::disk('public')->exists($this->imagen);
    }
}
