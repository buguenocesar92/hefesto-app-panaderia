<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\LoteProducto;
use App\Models\MotivoMerma;
use App\Services\Caducidad\RevisorDeCaducidad;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Marca los lotes vencidos y, si la panaderia lo pidio, registra su merma.
 *
 * POR QUE UN COMANDO Y NO SOLO UNA PANTALLA
 * ----------------------------------------
 * La caducidad no puede depender de que alguien se acuerde. El pan que paso
 * de fecha y sigue en el mostrador es merma que nadie registro, y eso es
 * justamente lo que el sistema existe para que no pase.
 *
 * Que la merma sea automatica o no lo decide la panaderia, producto por
 * producto, en la tabla `vigencias`. Con `merma_automatica` apagado, el lote
 * queda vencido y visible, esperando a que una persona decida.
 */
class PanaderiaMermarVencidos extends Command
{
    protected $signature = 'panaderia:mermar-vencidos {--fecha= : Fecha de referencia. Por omision, hoy.}';

    protected $description = 'Marca como vencidos los lotes que pasaron su fecha y registra su merma si la panaderia lo pidio.';

    public function handle(RevisorDeCaducidad $revisor): int
    {
        $fecha = $this->option('fecha') !== null
            ? Carbon::parse($this->option('fecha'))
            : Carbon::today();

        // 1. Marcar vencido. Esto es lo que impide vender pan caducado, y es
        // la parte que no depende de ninguna configuracion.
        $marcados = $revisor->marcarVencidos($fecha);

        if ($marcados['marcados'] > 0) {
            $this->info("Lotes marcados como vencidos: {$marcados['marcados']}");
        } else {
            $this->line('No hay lotes vencidos nuevos.');
        }

        // 2. Registrar la merma de los que la panaderia dejo automatica.
        $resultado = $revisor->mermarVencidos($fecha);

        if ($resultado['sin_motivo'] === true) {
            $this->warn('No hay un motivo de merma con el codigo "vencido" en esta panaderia.');
            $this->line('Los lotes vencidos quedaron marcados, pero NO se mermaron:');
            $this->line('perder mercaderia sin un motivo escrito seria peor que no perderla.');
            $this->line('Crea el motivo en Configuracion > Motivos de merma.');

            return self::SUCCESS;
        }

        if ($resultado['mermados'] > 0) {
            $this->info("Lotes mermados: {$resultado['mermados']}");

            foreach ($resultado['detalle'] as $fila) {
                $this->line("  - {$fila['codigo']}: {$fila['motivo']}");
            }
        }

        if ($resultado['omitidos'] > 0) {
            // No es un error: la panaderia eligio que la merma la registre
            // una persona.
            $this->info("Lotes vencidos que esperan decision de una persona: {$resultado['omitidos']}");

            foreach ($resultado['detalle'] as $fila) {
                if (($fila['motivo'] ?? '') !== 'merma_automatica_desactivada') {
                    continue;
                }

                $this->line("  - {$fila['codigo']}");
            }
        }

        Log::info('Revision de caducidad terminada', [
            'fecha' => $fecha->toDateString(),
            'marcados' => $marcados['marcados'],
            'mermados' => $resultado['mermados'],
            'omitidos' => $resultado['omitidos'],
        ]);

        return self::SUCCESS;
    }
}