<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TipoComprobante extends Model
{
    protected $table = 'tipos_comprobante';

    public $timestamps = false;

    protected $fillable = [
        'codigo', 'nombre', 'aplica_persona', 'exige_cliente', 'exige_documento', 'activo',
    ];

    protected function casts(): array
    {
        return [
            'exige_cliente' => 'boolean',
            'exige_documento' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    public function series(): HasMany
    {
        return $this->hasMany(SerieComprobante::class, 'tipo_comprobante_id');
    }
}
