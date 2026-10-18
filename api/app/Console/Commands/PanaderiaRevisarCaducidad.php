<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Caducidad\RevisorDeCaducidad;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Muestra que lotes se estan por vencer y cuales ya vencieron.
 *
 * Es de solo LECTURA: no marca ni merma nada. Sirve para que el encargado
 * mire el dia de antes y decida que hacer, en vez de que el sistema lo haga
 * solo. Para eso esta `panaderia:mermar-vencidos`.
 */
class PanaderiaRevisarCaducidad extends Command
{
    protected $signature = 'panaderia:revisar-caducidad {--dias= : Dias de anticipacion para avisar. Por omision, el valor de la configuracion.}';

    protected $description = 'Muestra los lotes por vencer y los vencidos. No modifica nada.';

    public function handle(RevisorDeCaducidad $revisor): int
    {
        $dias = $this->option('dias') !== null
            ? (int) $this->option('dias')
            : null;

        $resultado = $revisor->revisar(Carbon::today(), $dias);

        if ($resultado['por_vencer'] === []) {
            $this->line('No hay lotes por vencer.');
        } else {
            $this->info('Por vencer:');
            $this->table(
                ['Lote', 'Producto', 'Disponible', 'Vence', 'Dias'],
                array_map(fn ($l) => [
                    $l['codigo'],
                    $l['articulo'] ?? '(sin nombre)',
                    $l['cantidad_disponible'],
                    $l['fecha_vencimiento'],
                    $l['dias'] ?? '-',
                ], $resultado['por_vencer'])
            );
        }

        if ($resultado['vencidos'] === []) {
            $this->line('No hay lotes vencidos.');
        } else {
            $this->warn('VENCIDOS (no se pueden vender):');
            $this->table(
                ['Lote', 'Producto', 'Disponible', 'Vencio'],
                array_map(fn ($l) => [
                    $l['codigo'],
                    $l['articulo'] ?? '(sin nombre)',
                    $l['cantidad_disponible'],
                    $l['fecha_vencimiento'],
                ], $resultado['vencidos'])
            );
        }

        return self::SUCCESS;
    }
}