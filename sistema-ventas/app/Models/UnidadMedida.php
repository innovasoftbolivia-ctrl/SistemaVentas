<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Unidad en la que se vende el producto. `permite_decimal` decide si la
 * cantidad puede llevar fracción: 1.5 kg tiene sentido, 1.5 unidades no.
 */
class UnidadMedida extends Model
{
    protected $table = 'unidades_medida';

    public $timestamps = false;

    protected $fillable = ['codigo', 'nombre', 'permite_decimal'];

    protected function casts(): array
    {
        return ['permite_decimal' => 'boolean'];
    }

    public function productos(): HasMany
    {
        return $this->hasMany(Producto::class, 'unidad_medida_id');
    }

    public function getEtiquetaAttribute(): string
    {
        return "{$this->nombre} ({$this->codigo})";
    }
}
