<?php

namespace Tests\Feature;

use App\Services\Respaldos;
use Tests\TestCase;

/**
 * scripts/probar-restauracion.sh, ejecutado de verdad contra MySQL.
 *
 * Un respaldo que nunca se restauró es una promesa, no una red de seguridad.
 * Estas pruebas generan respaldos buenos y los rompen de las formas que pasan
 * en la vida real (cortado, corrupto, sin triggers, vacío) para asegurar que el
 * ensayo los distinga. Se saltan solas si no hay Docker con el contenedor de
 * MySQL del proyecto.
 *
 * El guion se corre desde una copia en una carpeta temporal para que su
 * constancia (backups/ultimo-ensayo-restauracion.txt) no pise la del sistema
 * de desarrollo.
 */
class EnsayoDeRestauracionTest extends TestCase
{
    private string $raiz;

    private string $contenedor = 'ventas_mysql';

    private string $base;

    private string $carpetaRespaldos = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = (string) config('database.connections.mysql.database');
        $this->raiz = sys_get_temp_dir().'/ensayo-'.bin2hex(random_bytes(4));
        mkdir($this->raiz.'/scripts', 0777, true);
        mkdir($this->raiz.'/backups', 0777, true);
        copy(base_path('../scripts/probar-restauracion.sh'), $this->raiz.'/scripts/probar-restauracion.sh');
        chmod($this->raiz.'/scripts/probar-restauracion.sh', 0755);
    }

    protected function tearDown(): void
    {
        $this->borrar($this->raiz);

        if ($this->carpetaRespaldos !== '') {
            $this->borrar($this->carpetaRespaldos);
        }

        parent::tearDown();
    }

    private function borrar(string $ruta): void
    {
        if (is_dir($ruta)) {
            foreach (array_diff(scandir($ruta), ['.', '..']) as $hijo) {
                $this->borrar($ruta.'/'.$hijo);
            }
            @rmdir($ruta);
        } elseif (file_exists($ruta)) {
            @unlink($ruta);
        }
    }

    private function bash(): string
    {
        $candidatos = PHP_OS_FAMILY === 'Windows'
            ? ['C:\\Program Files\\Git\\bin\\bash.exe', 'C:\\Program Files\\Git\\usr\\bin\\bash.exe']
            : ['bash'];
        $bash = collect($candidatos)->first(fn ($c) => PHP_OS_FAMILY !== 'Windows' || is_file($c));

        if (! $bash) {
            $this->markTestSkipped('No hay un bash para ejecutar el guion.');
        }

        return $bash;
    }

    /** @param  list<string>  $comando */
    private function ejecutar(array $comando, ?array $entorno = null): array
    {
        $proceso = proc_open($comando, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuberias, $this->raiz, $entorno);
        $salida = stream_get_contents($tuberias[1]);
        $error = stream_get_contents($tuberias[2]);
        $codigo = proc_close($proceso);

        return [$codigo, $salida, $error];
    }

    private function exigirMysql(): void
    {
        try {
            [$codigo, $salida] = $this->ejecutar(['docker', 'inspect', '-f', '{{.State.Running}}', $this->contenedor]);
        } catch (\Throwable) {
            $this->markTestSkipped('No hay docker.');
        }

        if ($codigo !== 0 || trim($salida) !== 'true') {
            $this->markTestSkipped("El contenedor {$this->contenedor} no está corriendo.");
        }
    }

    /** Un respaldo bueno de la base de pruebas, hecho con mysqldump (rutinas y triggers incluidos). */
    private function respaldoBueno(): string
    {
        [$codigo, $sql] = $this->ejecutar([
            'docker', 'exec', '-e', 'MYSQL_PWD=ventas123', $this->contenedor,
            'mysqldump', '--single-transaction', '--routines', '--triggers', '--events',
            '--default-character-set=utf8mb4', '-uroot', $this->base,
        ]);
        $this->assertSame(0, $codigo, 'no se pudo hacer el respaldo de prueba');

        return $sql;
    }

    private function guardar(string $nombre, string $contenido): string
    {
        $ruta = $this->raiz.'/'.$nombre;
        file_put_contents($ruta, $contenido);

        return $ruta;
    }

    /** @return array{0: int, 1: string} */
    private function ensayar(string $archivo): array
    {
        [$codigo, $salida, $error] = $this->ejecutar(
            [$this->bash(), $this->raiz.'/scripts/probar-restauracion.sh', $archivo],
            array_merge(getenv(), ['BASE' => $this->base, 'MSYS_NO_PATHCONV' => '1']),
        );

        return [$codigo, $salida.$error];
    }

    /** Una sentencia SQL directa contra la base de pruebas (fuera de la transacción del test). */
    private function sql(string $consulta): string
    {
        [$codigo, $salida] = $this->ejecutar([
            'docker', 'exec', '-e', 'MYSQL_PWD=ventas123', $this->contenedor,
            'mysql', '-uroot', '-N', $this->base, '-e', $consulta,
        ]);
        $this->assertSame(0, $codigo, "falló el SQL de prueba: {$consulta}");

        return $salida;
    }

    private function existeBase(string $nombre): bool
    {
        [, $salida] = $this->ejecutar([
            'docker', 'exec', '-e', 'MYSQL_PWD=ventas123', $this->contenedor,
            'mysql', '-uroot', '-N', '-e', "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '{$nombre}'",
        ]);

        return trim($salida) === '1';
    }

    private function constancia(): string
    {
        return (string) file_get_contents($this->raiz.'/backups/ultimo-ensayo-restauracion.txt');
    }

    // ============================================================ el respaldo bueno

    public function test_un_respaldo_bueno_se_restaura_y_la_base_temporal_desaparece(): void
    {
        $this->exigirMysql();
        $archivo = $this->guardar('bueno.sql.gz', gzencode($this->respaldoBueno()));

        [$codigo, $salida] = $this->ensayar($archivo);

        $this->assertSame(0, $codigo, $salida);
        $this->assertStringContainsString('SE PUEDE restaurar', $salida);
        $this->assertStringContainsString('mismas piezas que la base viva', $salida);
        $this->assertStringContainsString('el stock de todos los productos coincide con su kardex', $salida);
        $this->assertStringStartsWith('RESULTADO: OK', $this->constancia());
        $this->assertFalse($this->existeBase($this->base.'_ensayo'), 'la base temporal tiene que borrarse');
    }

    // ============================================================ los que se rompen

    public function test_un_respaldo_cortado_se_rechaza(): void
    {
        $this->exigirMysql();
        $sql = $this->respaldoBueno();
        $archivo = $this->guardar('cortado.sql.gz', gzencode(substr($sql, 0, intdiv(strlen($sql), 3))));

        [$codigo, $salida] = $this->ensayar($archivo);

        $this->assertSame(1, $codigo, $salida);
        $this->assertStringContainsString('incompleto', $salida);
        $this->assertStringStartsWith('RESULTADO: FALLÓ', $this->constancia());
        $this->assertFalse($this->existeBase($this->base.'_ensayo'));
    }

    public function test_un_gzip_corrupto_se_rechaza(): void
    {
        $this->exigirMysql();
        $archivo = $this->guardar('corrupto.sql.gz', substr(gzencode($this->respaldoBueno()), 0, 5000));

        [$codigo, $salida] = $this->ensayar($archivo);

        $this->assertSame(1, $codigo, $salida);
        // «está corrupto», no solo «corrupto»: esa palabra también está en el nombre del archivo.
        $this->assertStringContainsString('FALLA  el archivo está corrupto: gzip no lo puede leer', $salida);
    }

    public function test_un_respaldo_con_el_stock_descuadrado_del_kardex_se_rechaza(): void
    {
        $this->exigirMysql();

        $id = trim($this->sql('SELECT MIN(producto_id) FROM movimientos_inventario'));
        $this->assertNotSame('', $id, 'la base de pruebas necesita al menos un movimiento');

        // Se descuadra la base de pruebas un instante, solo para sacar el respaldo.
        $this->sql("UPDATE productos SET stock_actual = stock_actual + 7 WHERE id = {$id}");
        try {
            $sql = $this->respaldoBueno();
        } finally {
            $this->sql("UPDATE productos SET stock_actual = stock_actual - 7 WHERE id = {$id}");
        }

        [$codigo, $salida] = $this->ensayar($this->guardar('descuadrado.sql.gz', gzencode($sql)));

        $this->assertSame(1, $codigo, $salida);
        $this->assertStringContainsString('stock distinto del último movimiento del kardex', $salida);
    }

    // ============================================================ la vigilancia mensual

    /** @return array{0: string, 1: bool} la línea de salud sobre el ensayo y si el chequeo falló */
    private function saludDelEnsayo(?string $nota, int $diasDeLaNota = 0, int $diasDelEnv = 0): array
    {
        $raiz = $this->raiz.'/salud';
        @mkdir($raiz.'/scripts', 0777, true);
        @mkdir($raiz.'/backups', 0777, true);
        copy(base_path('../scripts/revisar-salud.sh'), $raiz.'/scripts/revisar-salud.sh');
        file_put_contents($raiz.'/.env', "DB_PASSWORD=x\n");
        touch($raiz.'/.env', time() - $diasDelEnv * 86400);

        $archivo = $raiz.'/backups/ultimo-ensayo-restauracion.txt';
        @unlink($archivo);

        if ($nota !== null) {
            file_put_contents($archivo, $nota);
            touch($archivo, time() - $diasDeLaNota * 86400);
        }

        $proceso = proc_open([$this->bash(), $raiz.'/scripts/revisar-salud.sh'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuberias, $raiz, array_merge(getenv(), ['MSYS_NO_PATHCONV' => '1']));
        $salida = stream_get_contents($tuberias[1]).stream_get_contents($tuberias[2]);
        proc_close($proceso);

        $linea = collect(explode("\n", $salida))->first(fn ($l) => preg_match('/(ensay|ensaya|restauraci)/iu', $l) && preg_match('/^\s*(ok|FALLA)\s/u', $l)) ?? '';

        return [trim($linea), str_contains($linea, 'FALLA')];
    }

    public function test_la_salud_avisa_cuando_nunca_se_ensayo_pasada_la_primera_semana(): void
    {
        [$linea, $fallo] = $this->saludDelEnsayo(null, diasDelEnv: 10);
        $this->assertTrue($fallo, $linea);
        $this->assertStringContainsString('nunca se ensayó restaurar', $linea);

        [$linea, $fallo] = $this->saludDelEnsayo(null, diasDelEnv: 1);
        $this->assertFalse($fallo, 'una instalación recién hecha tiene una semana de margen: '.$linea);
    }

    public function test_la_salud_avisa_si_el_ultimo_ensayo_fallo_o_es_viejo(): void
    {
        [$linea, $fallo] = $this->saludDelEnsayo("RESULTADO: FALLÓ\n");
        $this->assertTrue($fallo, $linea);
        $this->assertStringContainsString('FALLÓ', $linea);

        [$linea, $fallo] = $this->saludDelEnsayo("RESULTADO: OK\n", diasDeLaNota: 40);
        $this->assertTrue($fallo, $linea);
        $this->assertStringContainsString('hace más de 35 días', $linea);
    }

    public function test_la_salud_da_por_bueno_un_ensayo_reciente_y_exitoso(): void
    {
        [$linea, $fallo] = $this->saludDelEnsayo("RESULTADO: OK\n", diasDeLaNota: 3);

        $this->assertFalse($fallo, $linea);
        $this->assertStringContainsString('ensayada con éxito', $linea);
    }

    public function test_un_respaldo_sin_procedimientos_ni_triggers_se_rechaza(): void
    {
        $this->exigirMysql();
        $sql = $this->respaldoBueno();

        // Con la lógica en PHP (LOGICA_EN_PHP) la base de pruebas no tiene
        // procedimientos ni triggers: no hay nada que quitarle al volcado.
        if (! str_contains($sql, 'DELIMITER ;;')) {
            $this->markTestSkipped('La base de pruebas no tiene procedimientos ni triggers (modo con la lógica en PHP).');
        }

        // Lo que pasa con un mysqldump sin --routines --triggers: se ve completo.
        $sinLogica = preg_replace('/^DELIMITER ;;.*?^DELIMITER ;\s*$/ms', '', $sql);
        $this->assertNotSame($sql, $sinLogica, 'la prueba no logró quitar la lógica del volcado');

        [$codigo, $salida] = $this->ensayar($this->guardar('sin-logica.sql.gz', gzencode((string) $sinLogica)));

        $this->assertSame(1, $codigo, $salida);
        $this->assertStringContainsString('faltan piezas', $salida);
    }

    public function test_un_respaldo_de_una_base_vacia_se_rechaza(): void
    {
        $this->exigirMysql();
        $esquema = (string) file_get_contents(base_path('../docs/sql/01_schema_mysql.sql'));
        $esquema = preg_replace('/^USE ventas_db;/m', '', $esquema);
        $esquema .= "\n-- Fin del respaldo: completo.\n";

        [$codigo, $salida] = $this->ensayar($this->guardar('vacio.sql.gz', gzencode($esquema)));

        $this->assertSame(1, $codigo, $salida);
        $this->assertStringContainsString('VACÍA', $salida);
    }

    public function test_un_archivo_que_no_existe_no_toca_nada(): void
    {
        $this->exigirMysql();

        [$codigo, $salida] = $this->ensayar($this->raiz.'/no-existe.sql.gz');

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('No existe el archivo', $salida);
        $this->assertFalse($this->existeBase($this->base.'_ensayo'));
    }

    // ============================================================ el respaldo nocturno del propio sistema

    public function test_el_respaldo_nocturno_del_sistema_termina_con_su_marca_y_se_restaura(): void
    {
        $this->exigirMysql();
        $this->carpetaRespaldos = sys_get_temp_dir().'/respaldos-ensayo-'.bin2hex(random_bytes(4));
        config(['ventas.respaldos.ruta' => $this->carpetaRespaldos, 'ventas.respaldos.copia' => '']);

        $ruta = Respaldos::crear()['base'];
        $sql = (string) gzdecode((string) file_get_contents($ruta));

        $this->assertStringEndsWith("-- Fin del respaldo: completo.\n", $sql);

        [$codigo, $salida] = $this->ensayar($ruta);

        $this->assertSame(0, $codigo, $salida);
        $this->assertStringContainsString('SE PUEDE restaurar', $salida);
    }

    public function test_un_respaldo_nocturno_anterior_a_la_marca_sigue_valiendo(): void
    {
        $this->exigirMysql();
        $this->carpetaRespaldos = sys_get_temp_dir().'/respaldos-ensayo-'.bin2hex(random_bytes(4));
        config(['ventas.respaldos.ruta' => $this->carpetaRespaldos, 'ventas.respaldos.copia' => '']);

        $sql = (string) gzdecode((string) file_get_contents(Respaldos::crear()['base']));
        $anterior = str_replace("\n-- Fin del respaldo: completo.\n", '', $sql);
        $this->assertStringEndsWith("SET UNIQUE_CHECKS = 1;\n", $anterior);

        [$codigo, $salida] = $this->ensayar($this->guardar('anterior.sql.gz', gzencode($anterior)));

        $this->assertSame(0, $codigo, $salida);
    }

    // ============================================================ la vigilancia y las guardas

    public function test_el_ensayo_no_puede_apuntar_a_la_base_real_ni_a_nombres_ajenos(): void
    {
        $script = (string) file_get_contents(base_path('../scripts/probar-restauracion.sh'));

        $this->assertStringContainsString('ENSAYO="${BASE}_ensayo"', $script);
        $this->assertStringContainsString('trap limpiar EXIT', $script);
        $this->assertMatchesRegularExpression('/DROP DATABASE IF EXISTS \\\\`\$ENSAYO\\\\`/', $script);
        $this->assertDoesNotMatchRegularExpression('/DROP DATABASE[^\n]*\$BASE[^_]/', $script, 'el ensayo jamás borra la base real');

        $this->exigirMysql();
        [$codigo, $salida] = $this->ejecutar([$this->bash(), $this->raiz.'/scripts/probar-restauracion.sh'], array_merge(getenv(), ['BASE' => 'mysql']));
        $this->assertSame(2, $codigo, $salida);
    }

    public function test_la_revision_de_salud_vigila_el_ensayo_mensual(): void
    {
        $salud = (string) file_get_contents(base_path('../scripts/revisar-salud.sh'));

        $this->assertStringContainsString('ultimo-ensayo-restauracion.txt', $salud);
        $this->assertStringContainsString('ENSAYO_MAX_DIAS', $salud);
        $this->assertStringContainsString('RESULTADO: OK', $salud);
    }
}
