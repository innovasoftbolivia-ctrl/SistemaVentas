<?php

namespace App\Models;

use App\Services\ReglasEnPhp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bitácora de operaciones sensibles (RNF6). Nada se borra: se registra.
 */
class Auditoria extends Model
{
    protected static function booted(): void
    {
        // Réplicas de trg_auditoria_before_update y trg_auditoria_before_delete.
        static::updating(function () {
            if (ReglasEnPhp::activa()) {
                ReglasEnPhp::bitacoraInmutable('auditoria', 'editar');
            }
        });

        static::deleting(function () {
            if (ReglasEnPhp::activa()) {
                ReglasEnPhp::bitacoraInmutable('auditoria', 'borrar');
            }
        });
    }

    protected $table = 'auditoria';

    public $timestamps = false;

    protected $fillable = ['usuario_id', 'accion', 'entidad', 'entidad_id', 'detalle', 'ip', 'fecha'];

    protected function casts(): array
    {
        return [
            'detalle' => 'array',
            'fecha' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
}
