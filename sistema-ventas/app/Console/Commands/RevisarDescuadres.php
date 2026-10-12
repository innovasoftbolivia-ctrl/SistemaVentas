<?php

namespace App\Console\Commands;

use App\Services\Descuadres;
use Illuminate\Console\Command;

/**
 * Revisa que todo cuadre: stock contra kardex, kardex contra sí mismo, stock contra
 * lotes, ventas contra sus pagos, QR pagados sin venta.
 *
 *     php artisan descuadres:revisar
 *
 * Lo agenda el programador todas las noches, después del respaldo (routes/console.php).
 * Sale con error si algo no cuadra, para que un cron o un monitor lo noten.
 */
class RevisarDescuadres extends Command
{
    protected $signature = 'descuadres:revisar {--limite=30 : Cuántos descuadres mostrar}';

    protected $description = 'Revisa que el stock, el kardex, las ventas y los cobros cuadren entre sí';

    public function handle(): int
    {
        $r = Descuadres::revisar();

        if ($r['cantidad'] === 0) {
            $this->info('Todo cuadra.');

            return self::SUCCESS;
        }

        $this->error("Hay {$r['cantidad']} cosa(s) que no cuadran:");

        foreach ($r['por_regla'] as $regla => $n) {
            $this->line("  {$n} × ".(Descuadres::REGLAS[$regla] ?? $regla));
        }

        $this->newLine();
        $limite = max(1, (int) $this->option('limite'));

        foreach ($r['filas']->take($limite) as $f) {
            $this->line("  [{$f->regla}] {$f->detalle}: debería ser {$f->esperado} y hay {$f->actual}");
        }

        if ($r['cantidad'] > $limite) {
            $this->line('  … y '.($r['cantidad'] - $limite).' más (--limite=N para ver más).');
        }

        $this->newLine();
        $this->line('El kardex no se edita: un error se corrige con un movimiento nuevo (Inventario → Ajustar).');

        return self::FAILURE;
    }
}
