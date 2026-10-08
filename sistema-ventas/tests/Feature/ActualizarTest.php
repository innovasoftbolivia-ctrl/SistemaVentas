<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * scripts/actualizar.sh, ejecutado de verdad con `docker`, `git`, `npm` y
 * `curl` de mentira que anotan cada llamada en un registro.
 *
 * No se puede actualizar un servidor real en una prueba, pero sí lo que más
 * importa del guion: el ORDEN (el respaldo va primero) y que cada falla se
 * detenga donde debe sin tocar lo que viene después. Antes de este guion, un
 * `git pull` sin recompilar los estilos dejaba todas las pantallas en 500.
 */
class ActualizarTest extends TestCase
{
    private string $raiz;

    private string $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->raiz = sys_get_temp_dir().'/actualizar-'.bin2hex(random_bytes(4));
        $this->log = $this->raiz.'/registro.txt';

        foreach (['scripts', 'bin', 'sistema-ventas', 'backups', '.git'] as $carpeta) {
            mkdir($this->raiz.'/'.$carpeta, 0777, true);
        }

        file_put_contents($this->log, '');
        file_put_contents($this->raiz.'/.env', "DB_PASSWORD=x\n");
        file_put_contents($this->raiz.'/sistema-ventas/.env.docker', "APP_ENV=production\n");
        file_put_contents($this->raiz.'/docker-compose.prod.yml', "services: {}\n");

        copy(base_path('../scripts/actualizar.sh'), $this->raiz.'/scripts/actualizar.sh');
        chmod($this->raiz.'/scripts/actualizar.sh', 0755);

        $this->guion('scripts/backup-db.sh', <<<'SH'
echo "backup" >> "$LOG"
if [ "${FALLA:-}" = "backup" ]; then echo "mysqldump falló" >&2; exit 1; fi
echo "Base guardada en backups/ventas_db_20261008_010000.sql.gz (4.0K)"
SH);
        $this->guion('scripts/aplicar-parches.sh', <<<'SH'
echo "parches $*" >> "$LOG"
if [ "${FALLA:-}" = "parches" ]; then echo "parche roto" >&2; exit 1; fi
SH);
        $this->guion('scripts/revisar-salud.sh', <<<'SH'
echo "salud" >> "$LOG"
SH);
        $this->guion('bin/docker', <<<'SH'
echo "docker $*" >> "$LOG"
case "$1" in
  info) exit 0 ;;
  inspect)
    case "$*" in
      *State.Running*) [ "${MYSQL_CORRIENDO:-true}" = "true" ] && echo true || echo false ;;
      *Health.Status*) echo "${SALUD:-healthy}" ;;
    esac
    exit 0 ;;
  compose) [ "${FALLA:-}" = "compose" ] && exit 1; exit 0 ;;
esac
SH);
        $this->guion('bin/git', <<<'SH'
echo "git $*" >> "$LOG"
case "$1" in
  diff) [ "${CAMBIOS:-no}" = "si" ] && exit 1; exit 0 ;;
  status) [ "${CAMBIOS:-no}" = "si" ] && echo " M app/Foo.php"; exit 0 ;;
  rev-parse) if [ "$2" = "--short" ]; then echo nuevo12; else echo "abc1234def5678"; fi; exit 0 ;;
  pull) [ "${FALLA:-}" = "git" ] && { echo "fatal: no se puede avanzar" >&2; exit 1; }; exit 0 ;;
esac
SH);
        $this->guion('bin/npm', <<<'SH'
echo "npm $*" >> "$LOG"
[ "${FALLA:-}" = "npm" ] && [ "$1" = "ci" ] && exit 1
if [ "$1" = "run" ]; then
  [ "${FALLA:-}" = "build" ] && exit 1
  mkdir -p public/build
  [ "${SIN_MANIFEST:-no}" = "si" ] || echo '{}' > public/build/manifest.json
fi
SH);
        $this->guion('bin/curl', <<<'SH'
echo "curl $*" >> "$LOG"
url="${@: -1}"
if [[ " $* " == *" -w "* ]]; then
  case "$url" in
    */up) printf '%s' "${CODIGO_UP:-200}" ;;
    *.css) printf '%s' "${CODIGO_CSS:-200}" ;;
    *) printf '200' ;;
  esac
else
  case "$url" in
    */login)
      if [ "${LOGIN_ROTO:-no}" = "si" ]; then echo "<html>Error 500</html>"
      elif [ "${CSS_RELATIVO:-no}" = "si" ]; then echo '<link href="/build/assets/app-abc.css"><input name="usuario">'
      else echo '<link href="http://localhost:8100/build/assets/app-abc.css"><input name="usuario">'; fi ;;
  esac
fi
SH);
        $this->guion('bin/sleep', 'exit 0');
    }

    protected function tearDown(): void
    {
        $this->borrar($this->raiz);

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

    private function guion(string $ruta, string $cuerpo): void
    {
        file_put_contents($this->raiz.'/'.$ruta, "#!/usr/bin/env bash\n".$cuerpo."\n");
        chmod($this->raiz.'/'.$ruta, 0755);
    }

    /**
     * @param  list<string>  $argumentos
     * @param  array<string, string>  $entorno
     * @return array{0: int, 1: string}
     */
    private function actualizar(array $argumentos = [], array $entorno = [], string $entrada = ''): array
    {
        $candidatos = PHP_OS_FAMILY === 'Windows'
            ? ['C:\\Program Files\\Git\\bin\\bash.exe', 'C:\\Program Files\\Git\\usr\\bin\\bash.exe']
            : ['bash'];
        $bash = collect($candidatos)->first(fn ($c) => PHP_OS_FAMILY !== 'Windows' || is_file($c));

        if (! $bash) {
            $this->markTestSkipped('No hay un bash para ejecutar el guion.');
        }

        $entorno = array_merge(getenv(), [
            'LOG' => $this->log,
            'DOBLES' => $this->raiz.'/bin',
            'ESPERA_SEGUNDOS' => '3',
        ], $entorno);

        // Los dobles se anteponen al PATH DESDE DENTRO de bash: el bash de Git
        // reordena el PATH al arrancar y el `git` verdadero le ganaba al doble.
        $anteponer = 'PATH="$(cd "$DOBLES" && pwd):$PATH"; exec bash "$0" "$@"';

        $proceso = proc_open(
            array_merge([$bash, '-c', $anteponer, $this->raiz.'/scripts/actualizar.sh'], $argumentos),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $tuberias,
            $this->raiz,
            $entorno,
        );
        fwrite($tuberias[0], $entrada);
        fclose($tuberias[0]);
        $salida = stream_get_contents($tuberias[1]).stream_get_contents($tuberias[2]);
        $codigo = proc_close($proceso);

        return [$codigo, $salida];
    }

    /** @return list<string> las llamadas que hizo el guion, en orden */
    private function llamadas(): array
    {
        return array_values(array_filter(array_map('trim', file($this->log))));
    }

    private function hizo(string $prefijo): bool
    {
        foreach ($this->llamadas() as $llamada) {
            if (str_starts_with($llamada, $prefijo)) {
                return true;
            }
        }

        return false;
    }

    private function posicion(string $prefijo): int
    {
        foreach ($this->llamadas() as $i => $llamada) {
            if (str_starts_with($llamada, $prefijo)) {
                return $i;
            }
        }

        $this->fail("el guion no hizo «{$prefijo}»: ".implode(' | ', $this->llamadas()));
    }

    // ============================================================ el camino feliz

    public function test_actualiza_en_el_orden_que_protege_los_datos(): void
    {
        [$codigo, $salida] = $this->actualizar(['--si']);

        $this->assertSame(0, $codigo, $salida);

        $orden = ['backup', 'git pull --ff-only', 'npm ci', 'npm run build', 'docker compose -f docker-compose.prod.yml up -d --build', 'parches --aplicar', 'salud'];
        $posiciones = array_map($this->posicion(...), $orden);
        $ordenadas = $posiciones;
        sort($ordenadas);

        $this->assertSame($ordenadas, $posiciones, 'el orden tiene que ser: '.implode(' → ', $orden));
        $this->assertStringContainsString('Actualización terminada', $salida);
    }

    public function test_la_comprobacion_final_mira_la_salud_el_ingreso_y_los_estilos(): void
    {
        $this->actualizar(['--si']);

        $this->assertTrue($this->hizo('curl -s -o /dev/null -w %{http_code} --max-time 20 http://localhost:8100/up'));
        $this->assertTrue($this->hizo('curl -s --max-time 20 http://localhost:8100/login'));
        $this->assertTrue($this->hizo('curl -s -o /dev/null -w %{http_code} --max-time 20 http://localhost:8100/build/assets/app-abc.css'));
    }

    public function test_una_hoja_de_estilos_con_ruta_relativa_tambien_se_comprueba(): void
    {
        [$codigo] = $this->actualizar(['--si'], ['CSS_RELATIVO' => 'si']);

        $this->assertSame(0, $codigo);
        $this->assertTrue($this->hizo('curl -s -o /dev/null -w %{http_code} --max-time 20 http://localhost:8100/build/assets/app-abc.css'));
    }

    public function test_deja_constancia_de_la_version_anterior_y_del_respaldo(): void
    {
        $this->actualizar(['--si']);

        $nota = file_get_contents($this->raiz.'/backups/ultima-actualizacion.txt');
        $this->assertStringContainsString('abc1234def5678', $nota);
        $this->assertStringContainsString('backups/ventas_db_20261008_010000.sql.gz', $nota);
    }

    // ============================================================ no toca nada si no se puede

    public function test_si_el_respaldo_falla_no_se_toca_nada_mas(): void
    {
        [$codigo, $salida] = $this->actualizar(['--si'], ['FALLA' => 'backup']);

        $this->assertNotSame(0, $codigo);
        $this->assertStringContainsString('el respaldo falló', $salida);
        $this->assertStringContainsString('No se cambió nada', $salida);
        $this->assertFalse($this->hizo('git pull'));
        $this->assertFalse($this->hizo('npm'));
        $this->assertFalse($this->hizo('docker compose'));
        $this->assertFalse($this->hizo('parches'));
    }

    public function test_si_mysql_no_corre_no_hace_ni_el_respaldo(): void
    {
        [$codigo, $salida] = $this->actualizar(['--si'], ['MYSQL_CORRIENDO' => 'false']);

        $this->assertNotSame(0, $codigo);
        $this->assertStringContainsString('MySQL', $salida);
        $this->assertFalse($this->hizo('backup'));
        $this->assertFalse($this->hizo('git pull'));
    }

    public function test_con_cambios_a_mano_en_el_codigo_se_niega_antes_de_respaldar(): void
    {
        [$codigo, $salida] = $this->actualizar(['--si'], ['CAMBIOS' => 'si']);

        $this->assertNotSame(0, $codigo);
        $this->assertStringContainsString('cambios hechos a mano', $salida);
        $this->assertFalse($this->hizo('backup'));
        $this->assertFalse($this->hizo('git pull'));
    }

    public function test_un_servidor_sin_git_pide_usar_sin_git(): void
    {
        rmdir($this->raiz.'/.git');

        [$codigo, $salida] = $this->actualizar(['--si']);

        $this->assertNotSame(0, $codigo);
        $this->assertStringContainsString('--sin-git', $salida);
        $this->assertFalse($this->hizo('backup'));
    }

    public function test_sin_confirmacion_no_hace_nada(): void
    {
        [$codigo, $salida] = $this->actualizar([], [], "no\n");

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('No se tocó nada', $salida);
        $this->assertFalse($this->hizo('backup'));
    }

    public function test_con_la_confirmacion_escrita_actualiza(): void
    {
        [$codigo, $salida] = $this->actualizar([], [], "actualizar\n");

        $this->assertSame(0, $codigo, $salida);
        $this->assertTrue($this->hizo('docker compose'));
    }

    public function test_simular_no_cambia_nada_y_cuenta_el_plan(): void
    {
        [$codigo, $salida] = $this->actualizar(['--simular']);

        $this->assertSame(0, $codigo, $salida);
        $this->assertStringContainsString('No se cambió nada', $salida);
        $this->assertStringContainsString('Respaldo', $salida);

        foreach (['backup', 'git pull', 'npm', 'docker compose', 'parches'] as $accion) {
            $this->assertFalse($this->hizo($accion), "--simular no debía hacer «{$accion}»");
        }
    }

    // ============================================================ cuando algo falla a mitad

    public function test_si_el_codigo_no_avanza_dice_como_volver_y_donde_esta_el_respaldo(): void
    {
        [$codigo, $salida] = $this->actualizar(['--si'], ['FALLA' => 'git']);

        $this->assertNotSame(0, $codigo);
        $this->assertStringContainsString('git checkout abc1234def5678', $salida);
        $this->assertStringContainsString('backups/ventas_db_20261008_010000.sql.gz', $salida);
        $this->assertFalse($this->hizo('npm'));
        $this->assertFalse($this->hizo('docker compose'));
    }

    public function test_si_los_estilos_no_compilan_los_contenedores_siguen_intactos(): void
    {
        foreach (['npm', 'build'] as $falla) {
            file_put_contents($this->log, '');

            [$codigo, $salida] = $this->actualizar(['--si'], ['FALLA' => $falla]);

            $this->assertNotSame(0, $codigo, $falla);
            $this->assertStringContainsString('compilar los estilos', $salida);
            $this->assertStringContainsString('siguen con la versión anterior', $salida);
            $this->assertFalse($this->hizo('docker compose'), "con {$falla} fallando no se debe reiniciar nada");
            $this->assertFalse($this->hizo('parches'));
        }
    }

    public function test_sin_el_manifiesto_de_estilos_no_reinicia_aunque_npm_diga_que_termino(): void
    {
        [$codigo, $salida] = $this->actualizar(['--si'], ['SIN_MANIFEST' => 'si']);

        $this->assertNotSame(0, $codigo);
        $this->assertStringContainsString('error 500', $salida);
        $this->assertFalse($this->hizo('docker compose'));
    }

    public function test_si_la_aplicacion_no_queda_sana_no_aplica_parches(): void
    {
        [$codigo, $salida] = $this->actualizar(['--si'], ['SALUD' => 'starting']);

        $this->assertNotSame(0, $codigo);
        $this->assertStringContainsString('no llegó a estar sana', $salida);
        $this->assertFalse($this->hizo('parches'));
    }

    public function test_si_un_parche_falla_lo_dice_con_el_respaldo(): void
    {
        [$codigo, $salida] = $this->actualizar(['--si'], ['FALLA' => 'parches']);

        $this->assertNotSame(0, $codigo);
        $this->assertStringContainsString('aplicar los parches', $salida);
        $this->assertStringContainsString('restore-db.sh backups/ventas_db_20261008_010000.sql.gz', $salida);
        $this->assertFalse($this->hizo('curl'), 'no se da por buena una actualización con la base a medias');
    }

    // ============================================================ la comprobación final

    public function test_detecta_que_la_aplicacion_no_responde(): void
    {
        [$codigo, $salida] = $this->actualizar(['--si'], ['CODIGO_UP' => '500']);

        $this->assertNotSame(0, $codigo);
        $this->assertStringContainsString('/up no responde 200', $salida);
        $this->assertStringContainsString('git checkout', $salida);
    }

    public function test_detecta_las_pantallas_rotas_por_estilos_que_no_cargan(): void
    {
        [$codigo, $salida] = $this->actualizar(['--si'], ['CODIGO_CSS' => '404']);

        $this->assertNotSame(0, $codigo);
        $this->assertStringContainsString('la hoja de estilos no carga', $salida);
    }

    public function test_detecta_una_pantalla_de_ingreso_rota(): void
    {
        [$codigo, $salida] = $this->actualizar(['--si'], ['LOGIN_ROTO' => 'si']);

        $this->assertNotSame(0, $codigo);
        $this->assertStringContainsString('la pantalla de ingreso no se ve bien', $salida);
    }

    // ============================================================ opciones

    public function test_sin_git_y_sin_assets_omite_esos_pasos_pero_sigue_comprobando_los_estilos(): void
    {
        mkdir($this->raiz.'/sistema-ventas/public/build', 0777, true);
        file_put_contents($this->raiz.'/sistema-ventas/public/build/manifest.json', '{}');
        rmdir($this->raiz.'/.git');

        [$codigo, $salida] = $this->actualizar(['--si', '--sin-git', '--sin-assets']);

        $this->assertSame(0, $codigo, $salida);
        $this->assertFalse($this->hizo('git pull'));
        $this->assertFalse($this->hizo('npm'));
        $this->assertTrue($this->hizo('backup'));
        $this->assertTrue($this->hizo('docker compose'));
    }

    public function test_sin_assets_exige_que_los_estilos_ya_esten(): void
    {
        [$codigo, $salida] = $this->actualizar(['--si', '--sin-assets']);

        $this->assertNotSame(0, $codigo);
        $this->assertStringContainsString('error 500', $salida);
        $this->assertFalse($this->hizo('docker compose'));
    }

    public function test_una_opcion_desconocida_se_rechaza_sin_hacer_nada(): void
    {
        [$codigo, $salida] = $this->actualizar(['--rapido']);

        $this->assertSame(2, $codigo);
        $this->assertStringContainsString('Opción desconocida', $salida);
        $this->assertSame([], $this->llamadas());
    }
}
