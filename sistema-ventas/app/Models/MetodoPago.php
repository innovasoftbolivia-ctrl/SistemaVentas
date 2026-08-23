<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * `afecta_caja` distingue el dinero que queda físicamente en el cajón
 * (efectivo) del que no (tarjeta, transferencia). Solo el primero cuenta
 * para el arqueo de cierre.
 */
class MetodoPago extends Model
{
    protected $table = 'metodos_pago';

    public $timestamps = false;

    protected $fillable = ['codigo', 'nombre', 'afecta_caja', 'activo'];

    protected function casts(): array
    {
        return [
            'afecta_caja' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', 1);
    }

    public function esEfectivo(): bool
    {
        return $this->codigo === 'EFECTIVO';
    }
}
