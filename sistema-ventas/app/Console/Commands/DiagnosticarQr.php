<?php

namespace App\Console\Commands;

use App\Services\Qr\QrBaneco;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Revisa si el cobro por QR quedó bien configurado, sin mostrar ningún secreto.
 *
 * Es para el día en que el banco entregue las credenciales: se ponen en
 * `sistema-ventas/.env.docker` y este comando dice qué falta, a qué ambiente
 * apunta y —con `--conectar`— si el banco las acepta, sin generar ningún QR ni
 * mover dinero.
 *
 *     docker compose -f docker-compose.prod.yml exec app php artisan qr:diagnostico --conectar
 */
class DiagnosticarQr extends Command
{
    protected $signature = 'qr:diagnostico
        {--conectar : Además, pedirle un acceso al banco para comprobar el usuario y la contraseña}';

    protected $description = 'Revisa la configuración del cobro por QR (Banco Económico) sin mostrar secretos';

    public function handle(): int
    {
        $pasarela = (string) config('qr.pasarela', 'simulado');

        if ($pasarela === 'simulado') {
            $this->info('Cobro por QR: SIMULADO.');
            $this->line('  El QR se dibuja pero no hay banco detrás: el cajero confirma el pago a mano.');
            $this->line('  Para cobrar con Banco Económico: QR_PASARELA=baneco y las QR_BANECO_* en sistema-ventas/.env.docker.');

            return self::SUCCESS;
        }

        if ($pasarela !== QrBaneco::CODIGO) {
            $this->warn("Cobro por QR con «{$pasarela}»: este diagnóstico solo revisa Banco Económico.");

            return self::SUCCESS;
        }

        $config = (array) config('qr.pasarelas.baneco');
        $faltan = [];

        $this->info('Cobro por QR: Banco Económico (BEC QR Connect).');
        $this->newLine();

        // Variables: se dice si están, nunca su valor (salvo la dirección, que no es secreta).
        $this->line('Variables en sistema-ventas/.env.docker:');

        $filas = [
            ['QR_BANECO_URL', 'url_base', true],
            ['QR_BANECO_USUARIO', 'usuario', true],
            ['QR_BANECO_PASSWORD', 'password', true],
            ['QR_BANECO_LLAVE', 'llave', true],
            ['QR_BANECO_CUENTA', 'cuenta', true],
            ['QR_BANECO_SUCURSAL', 'sucursal', false],
        ];

        foreach ($filas as [$variable, $clave, $obligatoria]) {
            $valor = trim((string) ($config[$clave] ?? ''));

            if ($valor === '') {
                $this->line(sprintf('  %-20s %s', $variable, $obligatoria ? '<fg=red>FALTA</>' : '<fg=gray>vacía (opcional)</>'));

                if ($obligatoria) {
                    $faltan[] = $variable;
                }

                continue;
            }

            if ($clave === 'llave' && strlen($valor) !== 32) {
                $this->line(sprintf('  %-20s <fg=red>mal: tiene %d caracteres y tienen que ser 32</>', $variable, strlen($valor)));
                $faltan[] = $variable;

                continue;
            }

            $this->line(sprintf('  %-20s <fg=green>puesta</>', $variable));
        }

        $this->newLine();

        // Ambiente.
        $banco = new QrBaneco($config);
        $servidor = (string) (parse_url((string) ($config['url_base'] ?? ''), PHP_URL_HOST) ?: '—');
        $enPruebas = $banco->enPruebas();
        $produccion = app()->environment('production');

        $this->line('Ambiente:');
        $this->line("  Servidor del banco:  {$servidor}");
        $this->line('  Es de:               '.($enPruebas ? '<fg=yellow>PRUEBAS (certificación): los pagos no son reales</>' : '<fg=green>producción</>'));
        $this->line('  Este servidor es de: '.($produccion ? 'producción' : app()->environment()));

        $bloqueado = $produccion && $enPruebas && ! ($config['permitir_pruebas'] ?? false);

        if ($bloqueado) {
            $this->newLine();
            $this->error('  BLOQUEADO: un servidor de producción no cobra contra el ambiente de pruebas.');
            $this->line('  Pon en QR_BANECO_URL la dirección de producción que entregó el banco, o, si es la');
            $this->line('  fase de certificación en este servidor, QR_BANECO_PERMITIR_PRUEBAS=true.');
            $faltan[] = 'ambiente';
        } elseif ($enPruebas) {
            $this->line('  <fg=yellow>El mostrador avisará «ambiente de PRUEBAS» en cada QR.</>');
        }

        $this->newLine();

        // Lo que hay que darle al banco.
        $aviso = route('qr.aviso.baneco');
        $this->line('Dirección del aviso de pago, para dársela al banco:');
        $this->line("  {$aviso}");

        if (! str_starts_with($aviso, 'https://') || str_contains($aviso, 'localhost')) {
            $this->line('  <fg=yellow>Ojo: APP_URL todavía no es la dirección pública con https. El banco no podría avisar.</>');
        }

        $this->line('  (El aviso no trae firma: el sistema solo lo usa para consultar al banco, nunca lo da por cierto.)');

        // Conexión.
        if ($this->option('conectar')) {
            $this->newLine();
            $this->line('Conexión con el banco:');

            if ($faltan !== []) {
                $this->warn('  No se intentó: faltan datos arriba.');
            } else {
                try {
                    $banco->probarAcceso();
                    $this->line('  <fg=green>El banco aceptó el usuario y la contraseña.</>');
                } catch (RuntimeException $e) {
                    $this->line('  <fg=red>Falló: '.$e->getMessage().'</>');
                    $faltan[] = 'conexión';
                } catch (Throwable $e) {
                    $this->line('  <fg=red>Falló de forma inesperada ('.class_basename($e).'). Revisa storage/logs.</>');
                    $faltan[] = 'conexión';
                }
            }
        } else {
            $this->newLine();
            $this->line('Para comprobar el usuario y la contraseña contra el banco: php artisan qr:diagnostico --conectar');
        }

        $this->newLine();

        if ($faltan !== []) {
            $this->error('Falta resolver: '.implode(', ', array_unique($faltan)).'.');

            return self::FAILURE;
        }

        $this->info('Todo en orden para cobrar por QR.');

        return self::SUCCESS;
    }
}
