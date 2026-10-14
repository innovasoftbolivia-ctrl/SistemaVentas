<?php

namespace App\Console\Commands;

use App\Services\Archivador;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Mueve la bitácora y el kardex de más de un año a su archivo.
 *
 *     php artisan archivar:anual --simular          # cuenta, no mueve nada
 *     php artisan archivar:anual                    # hasta el 1 de enero del año pasado
 *     php artisan archivar:anual --hasta=2025-01-01
 *
 * Hace falta una cuenta de MySQL con DELETE sobre `auditoria` y `movimientos_inventario`:
 * `ventas_app` no la tiene, a propósito. Se le da SOLO a esta corrida, para que esa
 * clave no viva en el `.env`:
 *
 *     docker compose -f docker-compose.prod.yml exec \
 *         -e DB_ARCHIVO_USERNAME=root -e DB_ARCHIVO_PASSWORD="$DB_PASSWORD" app php artisan archivar:anual
 *
 * Conviene correrlo con un respaldo reciente y fuera del horario de atención.
 */
class ArchivarAnual extends Command
{
    protected $signature = 'archivar:anual
        {--hasta= : Archiva lo anterior a esta fecha (AAAA-MM-DD). Por omisión, el 1 de enero del año pasado}
        {--simular : Solo cuenta lo que se archivaría}
        {--forzar : Permite archivar lo que tiene menos de un año}
        {--si : No pide confirmación}';

    protected $description = 'Mueve la bitácora y el kardex de más de un año a su archivo (no borra nada)';

    public function handle(): int
    {
        try {
            $hasta = $this->option('hasta')
                ? Carbon::createFromFormat('Y-m-d', (string) $this->option('hasta'))->startOfDay()
                : Archivador::corteSugerido();
        } catch (\Throwable) {
            $this->error('La fecha de «--hasta» tiene que ser AAAA-MM-DD.');

            return self::INVALID;
        }

        $conexion = $this->conexionConPermiso();

        try {
            $plan = Archivador::archivar($hasta, $conexion, simular: true, forzar: (bool) $this->option('forzar'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('Lo anterior al '.$hasta->format('d/m/Y').':');
        $this->line('  bitácora: '.number_format($plan['auditoria']).' registros');
        $this->line('  kardex:   '.number_format($plan['movimientos']).' movimientos (se queda el último de cada producto)');

        if ($this->option('simular')) {
            $this->info('Simulación: no se movió nada.');

            return self::SUCCESS;
        }

        if ($plan['auditoria'] + $plan['movimientos'] === 0) {
            $this->info('No hay nada que archivar.');

            return self::SUCCESS;
        }

        if (! $this->option('si') && ! $this->confirm('¿Los muevo al archivo? Se copian, se comprueba y recién entonces se sacan de las tablas de uso diario.', false)) {
            $this->warn('No se movió nada.');

            return self::FAILURE;
        }

        try {
            $r = Archivador::archivar($hasta, $conexion, forzar: (bool) $this->option('forzar'));
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'command denied') || str_contains($e->getMessage(), '1142')) {
                $this->error('La cuenta de MySQL no tiene permiso de DELETE sobre esas tablas. Pásale DB_ARCHIVO_USERNAME y DB_ARCHIVO_PASSWORD a esta corrida (ver la ayuda del comando).');

                return self::FAILURE;
            }

            throw $e;
        }

        $this->info('Archivado: '.number_format($r['auditoria']).' registros de la bitácora y '.number_format($r['movimientos']).' movimientos del kardex.');
        $this->line('Se consultan desde Sistema → Bitácora y Almacén → Movimientos, con «Ver el archivo».');

        return self::SUCCESS;
    }

    /**
     * La conexión con permiso de borrado: la normal con otra cuenta si se la dieron
     * (`DB_ARCHIVO_*`), o la normal a secas.
     */
    private function conexionConPermiso(): ?string
    {
        $usuario = config('ventas.archivo.usuario');

        if (! $usuario) {
            $this->warn('Sin DB_ARCHIVO_USERNAME: se usa la cuenta normal, que solo sirve si tiene DELETE sobre la bitácora y el kardex.');

            return null;
        }

        config(['database.connections.archivo' => array_merge(config('database.connections.'.config('database.default')), [
            'username' => $usuario,
            'password' => (string) config('ventas.archivo.clave'),
        ])]);

        return 'archivo';
    }
}
