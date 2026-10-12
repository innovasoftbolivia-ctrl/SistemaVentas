<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * La ruta de actualización de una base que YA está en un cliente: los tres parches del
 * bloque 4 contra una base con el esquema anterior.
 *
 * No alcanza con que el esquema nuevo cargue limpio (eso lo prueba cualquier corrida de
 * la batería): el cliente que ya tiene ventas no recarga el esquema, aplica parches. Aquí
 * se toma una copia real de la base de pruebas, se la devuelve al estado anterior, se le
 * aplican los parches —dos veces— y se comprueba que queda IGUAL a una base nueva. Y que,
 * si tiene datos que incumplirían una restricción, el parche se detiene diciendo cuántos.
 *
 * Corre contra el MySQL de Docker; se salta sola si no está (o si la base de pruebas no
 * tiene triggers, o sea en el modo con la lógica en PHP).
 */
class ParchesDelBloque4Test extends TestCase
{
    private const PARCHES = [
        '2026_10_09_indices_de_la_base.sql',
        '2026_10_09_restricciones_y_bitacora.sql',
        '2026_10_09_descuadres_y_peso_del_qr.sql',
    ];

    private string $contenedor = 'ventas_mysql';

    private string $original;

    private string $clon;

    protected function setUp(): void
    {
        parent::setUp();

        $this->original = (string) config('database.connections.mysql.database');
        $this->clon = $this->original.'_parche';

        try {
            [$codigo, $salida] = $this->ejecutar(['docker', 'inspect', '-f', '{{.State.Running}}', $this->contenedor]);
        } catch (\Throwable) {
            $this->markTestSkipped('No hay docker.');
        }

        if ($codigo !== 0 || trim($salida) !== 'true') {
            $this->markTestSkipped("El contenedor {$this->contenedor} no está corriendo.");
        }

        if ($this->consultar('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()', $this->original) === '0') {
            $this->markTestSkipped('La base de pruebas no tiene triggers (modo con la lógica en PHP).');
        }

        $this->clonar();
    }

    protected function tearDown(): void
    {
        try {
            $this->sql("DROP DATABASE IF EXISTS `{$this->clon}`");
        } catch (\Throwable) {
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------ utilidades

    /** @param  list<string>  $comando */
    private function ejecutar(array $comando, ?string $entrada = null): array
    {
        $proceso = proc_open($comando, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuberias);
        fwrite($tuberias[0], (string) $entrada);
        fclose($tuberias[0]);
        $salida = stream_get_contents($tuberias[1]).stream_get_contents($tuberias[2]);

        return [proc_close($proceso), $salida];
    }

    /** Corre SQL contra una base (la del clon por omisión). Devuelve [código, salida]. */
    private function sql(string $sql, ?string $base = null): array
    {
        return $this->ejecutar([
            'docker', 'exec', '-i', '-e', 'MYSQL_PWD=ventas123', $this->contenedor,
            'mysql', '--default-character-set=utf8mb4', '-uroot', ...($base === null ? [] : [$base]),
        ], $sql);
    }

    private function consultar(string $sql, string $base): string
    {
        [, $salida] = $this->ejecutar([
            'docker', 'exec', '-e', 'MYSQL_PWD=ventas123', $this->contenedor,
            'mysql', '-uroot', '-N', $base, '-e', $sql,
        ]);

        return trim(preg_replace('/mysql: \[Warning\][^\n]*\n?/', '', $salida));
    }

    private function clonar(): void
    {
        [, $volcado] = $this->ejecutar([
            'docker', 'exec', '-e', 'MYSQL_PWD=ventas123', $this->contenedor,
            'mysqldump', '--single-transaction', '--routines', '--triggers', '--default-character-set=utf8mb4', '-uroot', $this->original,
        ]);

        $this->sql("DROP DATABASE IF EXISTS `{$this->clon}`; CREATE DATABASE `{$this->clon}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
        [$codigo, $salida] = $this->sql($volcado, $this->clon);
        $this->assertSame(0, $codigo, 'no se pudo clonar la base de pruebas: '.substr($salida, 0, 300));
    }

    /** Devuelve el clon al estado de ANTES de los parches (esquema del 8 de octubre). */
    private function retroceder(): void
    {
        [$codigo, $salida] = $this->sql(<<<'SQL'
ALTER TABLE movimientos_inventario DROP CHECK ck_movinv_cadena;
ALTER TABLE devoluciones DROP CHECK ck_devol_total;
ALTER TABLE ventas DROP CHECK ck_ventas_anulacion;
ALTER TABLE sesiones_caja DROP CHECK ck_sesion_cierre;
ALTER TABLE comprobantes DROP CHECK ck_comprobante_anulado;
DROP TRIGGER trg_devoluciones_before_insert;
DROP TRIGGER trg_movimientos_caja_before_insert;
DROP TRIGGER trg_auditoria_before_update;
DROP TRIGGER trg_auditoria_before_delete;
DROP TRIGGER trg_movimientos_inventario_before_update;
DROP TRIGGER trg_movimientos_inventario_before_delete;
DROP VIEW v_descuadres;
ALTER TABLE venta_detalle ADD INDEX ix_detalle_venta (venta_id);
ALTER TABLE clientes ADD INDEX ix_clientes_persona (tipo_persona);
ALTER TABLE empleados ADD INDEX ix_empleados_estado (estado);
ALTER TABLE productos ADD INDEX ix_productos_activo (activo);
ALTER TABLE productos DROP INDEX ix_productos_activo_nombre;
ALTER TABLE ventas ADD INDEX ix_ventas_fecha (fecha);
ALTER TABLE ventas DROP INDEX ix_ventas_fecha_cubre;
ALTER TABLE movimientos_caja DROP INDEX ix_movcaja_fecha;
SQL, $this->clon);
        $this->assertSame(0, $codigo, 'no se pudo retroceder el clon: '.$salida);
    }

    /** @return list<string> */
    private function firma(string $base): array
    {
        $salida = $this->consultar(<<<SQL
SELECT x FROM (
  SELECT CONCAT('IDX ', TABLE_NAME, '.', INDEX_NAME, ':', GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX)) AS x
    FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = '{$base}' GROUP BY TABLE_NAME, INDEX_NAME
  UNION ALL SELECT CONCAT('CHK ', TABLE_NAME, '.', CONSTRAINT_NAME)
    FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = '{$base}' AND CONSTRAINT_TYPE = 'CHECK'
  UNION ALL SELECT CONCAT('TRG ', TRIGGER_NAME) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = '{$base}'
  UNION ALL SELECT CONCAT('VISTA ', TABLE_NAME) FROM information_schema.VIEWS WHERE TABLE_SCHEMA = '{$base}'
) t ORDER BY x
SQL, $base);

        return array_values(array_filter(explode("\n", $salida)));
    }

    /** @return array{0: int, 1: string} */
    private function aplicar(string $parche): array
    {
        return $this->sql((string) file_get_contents(base_path('../docs/sql/parches/'.$parche)), $this->clon);
    }

    private function aplicarTodos(): void
    {
        foreach (self::PARCHES as $parche) {
            [$codigo, $salida] = $this->aplicar($parche);
            $this->assertSame(0, $codigo, "{$parche}: ".$salida);
            $this->assertStringNotContainsString('ERROR', $salida, $parche);
        }
    }

    // ------------------------------------------------------------------ las pruebas

    public function test_los_parches_dejan_una_base_vieja_igual_a_una_nueva(): void
    {
        $this->retroceder();
        $this->assertNotSame($this->firma($this->original), $this->firma($this->clon), 'el clon tendría que haber retrocedido');

        $this->aplicarTodos();

        $this->assertSame($this->firma($this->original), $this->firma($this->clon));
    }

    public function test_los_parches_se_pueden_aplicar_dos_veces(): void
    {
        $this->retroceder();
        $this->aplicarTodos();
        $primera = $this->firma($this->clon);

        $this->aplicarTodos();

        $this->assertSame($primera, $this->firma($this->clon));
    }

    public function test_el_parche_vacia_las_imagenes_de_qr_que_ya_no_hacen_falta(): void
    {
        $this->retroceder();
        $this->sql(<<<'SQL'
INSERT INTO sesiones_caja (caja_id, usuario_apertura_id, monto_inicial) SELECT MIN(c.id), MIN(u.id), 0 FROM cajas c, usuarios u;
SET @s := (SELECT MAX(id) FROM sesiones_caja);
SET @u := (SELECT MIN(id) FROM usuarios);
INSERT INTO cobros_qr (sesion_caja_id, usuario_id, monto, pasarela, payload, estado, pagado_en, confirmado_por)
  VALUES (@s, @u, 10, 'prueba', 'IMAGEN-PESADA', 'PAGADO', NOW(), 'MANUAL');
INSERT INTO cobros_qr (sesion_caja_id, usuario_id, monto, pasarela, payload, estado) VALUES (@s, @u, 12, 'prueba', 'IMAGEN-VIVA', 'PENDIENTE');
SQL, $this->clon);

        $this->aplicarTodos();

        $this->assertSame('', $this->consultar("SELECT payload FROM cobros_qr WHERE estado = 'PAGADO' AND payload IS NOT NULL", $this->clon));
        $this->assertSame('IMAGEN-VIVA', $this->consultar("SELECT payload FROM cobros_qr WHERE estado = 'PENDIENTE'", $this->clon), 'el pendiente conserva la suya');
    }

    public function test_si_hay_datos_que_incumplen_una_restriccion_el_parche_se_detiene_y_lo_dice(): void
    {
        $this->retroceder();
        // Un kardex con una cuenta mal hecha, como la que dejaría una edición a mano.
        $this->sql('UPDATE movimientos_inventario SET stock_resultante = stock_resultante + 1 ORDER BY id LIMIT 1', $this->clon);

        [$codigo, $salida] = $this->aplicar('2026_10_09_restricciones_y_bitacora.sql');

        $this->assertNotSame(0, $codigo, 'tendría que haberse detenido');
        $this->assertStringContainsString('DETENIDO: 1 fila(s) incumplen ck_movinv_cadena', $salida);
        $this->assertNotContains('CHK movimientos_inventario.ck_movinv_cadena', $this->firma($this->clon), 'la restricción no queda a medias');

        // Se arregla el dato y el mismo parche pasa.
        $this->sql('UPDATE movimientos_inventario SET stock_resultante = stock_resultante - 1 ORDER BY id LIMIT 1', $this->clon);
        [$codigo, $salida] = $this->aplicar('2026_10_09_restricciones_y_bitacora.sql');

        $this->assertSame(0, $codigo, $salida);
        $this->assertContains('CHK movimientos_inventario.ck_movinv_cadena', $this->firma($this->clon));
    }

    public function test_el_parche_de_catalogo_viejo_corrige_costos_sin_dejar_el_kardex_desprotegido(): void
    {
        // El parche sin_impuesto.sql corrige el costo de la carga inicial: es la única edición del
        // kardex que se permite, y solo dentro de ese parche. La guarda tiene que volver a su sitio.
        $this->sql("UPDATE productos SET precio_compra = precio_compra + 1 WHERE codigo = 'P-0001'", $this->clon);
        $desfase = $this->consultar("SELECT COUNT(*) FROM movimientos_inventario m JOIN productos p ON p.id = m.producto_id WHERE m.origen = 'INICIAL' AND NOT (m.costo_unitario <=> p.precio_compra)", $this->clon);
        $this->assertNotSame('0', $desfase);

        [$codigo, $salida] = $this->sql((string) file_get_contents(base_path('../docs/sql/parches/2026_08_23_sin_impuesto.sql')), $this->clon);
        $this->assertSame(0, $codigo, $salida);

        $this->assertSame('0', $this->consultar("SELECT COUNT(*) FROM movimientos_inventario m JOIN productos p ON p.id = m.producto_id WHERE m.origen = 'INICIAL' AND NOT (m.costo_unitario <=> p.precio_compra)", $this->clon));
        $this->assertContains('TRG trg_movimientos_inventario_before_update', $this->firma($this->clon));

        [$codigo, $salida] = $this->sql('UPDATE movimientos_inventario SET motivo = \'x\' LIMIT 1', $this->clon);
        $this->assertNotSame(0, $codigo);
        $this->assertStringContainsString('El kardex no se edita', $salida);
    }
}
