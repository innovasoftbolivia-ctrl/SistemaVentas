<?php

namespace App\Console\Commands;

use App\Services\ImportadorCatalogo;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Carga el catálogo inicial de un negocio desde un CSV o un Excel.
 *
 *     php artisan catalogo:importar --plantilla > catalogo.csv       (la plantilla para llenar)
 *     php artisan catalogo:importar catalogo.csv                      (solo REVISA, no carga nada)
 *     php artisan catalogo:importar catalogo.csv --aplicar            (carga)
 *
 * En Docker, el archivo tiene que estar dentro del contenedor:
 *
 *     docker cp catalogo.csv ventas_app_prod:/tmp/catalogo.csv
 *     docker compose -f docker-compose.prod.yml exec app php artisan catalogo:importar /tmp/catalogo.csv
 */
class ImportarCatalogo extends Command
{
    protected $signature = 'catalogo:importar
        {archivo? : El .csv o .xlsx con un producto por fila}
        {--aplicar : Cargar de verdad. Sin esto solo se revisa}
        {--crear-categorias : Crear las categorías y proveedores que no existan, en vez de marcarlos como error}
        {--plantilla : Escribir la plantilla de ejemplo y salir}
        {--errores=30 : Cuántos errores mostrar}';

    protected $description = 'Carga el catálogo inicial desde un CSV o Excel (revisa primero; carga solo con --aplicar)';

    public function handle(ImportadorCatalogo $importador): int
    {
        if ($this->option('plantilla')) {
            $this->output->write(ImportadorCatalogo::plantilla());

            return self::SUCCESS;
        }

        $archivo = (string) $this->argument('archivo');

        if ($archivo === '') {
            $this->error('Falta el archivo. Para obtener la plantilla: php artisan catalogo:importar --plantilla');

            return self::FAILURE;
        }

        try {
            $r = $importador->importar($archivo, (bool) $this->option('aplicar'), (bool) $this->option('crear-categorias'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("Filas leídas:      {$r['filas']}");
        $this->line("Filas correctas:   {$r['validas']}");
        $this->line('Filas con error:   '.count(array_unique(array_column($r['errores'], 'fila'))));
        $this->line("Con stock inicial: {$r['con_stock']}");

        if ($r['categorias_nuevas']) {
            $this->line('Categorías nuevas: '.implode(', ', $r['categorias_nuevas']));
        }

        if ($r['proveedores_nuevos']) {
            $this->line('Proveedores nuevos: '.implode(', ', $r['proveedores_nuevos']));
        }

        if ($r['errores']) {
            $this->newLine();
            $limite = max(1, (int) $this->option('errores'));

            foreach (array_slice($r['errores'], 0, $limite) as $e) {
                $this->line("  fila {$e['fila']}: {$e['mensaje']}");
            }

            if (count($r['errores']) > $limite) {
                $this->line('  … y '.(count($r['errores']) - $limite).' más (--errores=N para ver más).');
            }

            $this->newLine();
            $this->error('No se cargó nada: corrige el archivo y vuelve a correrlo. O entra todo o no entra nada.');

            return self::FAILURE;
        }

        if (! $r['aplicado']) {
            $this->newLine();
            $this->info('El archivo está bien. No se cargó nada todavía: corre de nuevo con --aplicar.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info("Listo: {$r['creados']} productos cargados. El stock inicial quedó en el kardex.");

        return self::SUCCESS;
    }
}
