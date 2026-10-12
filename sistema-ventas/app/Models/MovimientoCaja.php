<?php

namespace App\Models;

use App\Services\ReglasEnPhp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Entradas y salidas de efectivo que no son ventas: un adelanto, la compra
 * de una bolsa de hielo, el retiro parcial del dueño.
 */
class MovimientoCaja extends Model
{
    protected static function booted(): void
    {
        // Réplica de trg_movimientos_caja_before_insert.
        static::creating(function (self $movimiento) {
            if (ReglasEnPhp::activa()) {
                ReglasEnPhp::antesDeInsertarMovimientoCaja($movimiento->sesion_caja_id);
            }
        });
    }

    protected $table = 'movimientos_caja';

    public $timestamps = false;

    protected $fillable = ['sesion_caja_id', 'usuario_id', 'tipo', 'concepto', 'monto', 'anula_a_id', 'fecha'];

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'fecha' => 'datetime',
        ];
    }

    public function sesion(): BelongsTo
    {
        return $this->belongsTo(SesionCaja::class, 'sesion_caja_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    /** Si este movimiento es una anulación: el que anula. */
    public function anulaA(): BelongsTo
    {
        return $this->belongsTo(self::class, 'anula_a_id');
    }

    /** El contra-asiento que anuló a este movimiento, si lo hay. */
    public function anulacion(): HasOne
    {
        return $this->hasOne(self::class, 'anula_a_id');
    }

    public function esAnulacion(): bool
    {
        return $this->anula_a_id !== null;
    }

    public function estaAnulado(): bool
    {
        return $this->anulacion !== null;
    }
}
