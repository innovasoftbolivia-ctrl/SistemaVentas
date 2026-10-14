<?php

namespace App\Models;

/**
 * Lo que la bitácora tenía de más de un año (ver App\Services\Archivador).
 *
 * Es la misma tabla con otro nombre: se lee igual y, como la original, no se edita
 * ni se borra (triggers en la base; su gemelo en PHP viene heredado del modelo).
 */
class AuditoriaArchivo extends Auditoria
{
    protected $table = 'auditoria_archivo';
}
