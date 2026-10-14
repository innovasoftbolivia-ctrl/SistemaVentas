<?php

namespace App\Models;

/**
 * Lo que el kardex tenía de más de un año (ver App\Services\Archivador): la misma
 * tabla con otro nombre, que se lee con las mismas pantallas.
 */
class MovimientoInventarioArchivo extends MovimientoInventario
{
    protected $table = 'movimientos_inventario_archivo';
}
