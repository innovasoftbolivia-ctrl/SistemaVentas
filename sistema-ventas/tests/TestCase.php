<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Cerrojo de seguridad: ninguna prueba debe poder escribir en una base
     * que no sea de pruebas.
     *
     * `phpunit.xml` fija DB_DATABASE=ventas_db_test, pero dentro del
     * contenedor de la app esas variables no ganan —el contenedor ya trae
     * DB_HOST/DB_DATABASE de desarrollo como variables de entorno reales, y
     * algo en el arranque las vuelve a imponer incluso con `force="true"` en
     * el XML—, así que las pruebas terminan corriendo contra `ventas_db` en
     * silencio. En vez de perseguir esa precedencia de variables de entorno,
     * esto revienta la corrida entera a la primera prueba si la base activa
     * no termina en `_test`, sea cual sea la causa. Corre las pruebas con el
     * PHP del host (ver README, sección «Pruebas»), no con
     * `docker exec ... artisan test`.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $base = DB::connection()->getDatabaseName();

        if (! str_ends_with($base, '_test')) {
            throw new RuntimeException(
                "Las pruebas están corriendo contra «{$base}», que no es una base de pruebas. ".
                'Corre las pruebas con el PHP del host (README, sección «Pruebas»), no con '.
                '`docker exec ... artisan test`: dentro del contenedor las variables de entorno '.
                'de desarrollo pisan a las de phpunit.xml en silencio.'
            );
        }
    }
}
