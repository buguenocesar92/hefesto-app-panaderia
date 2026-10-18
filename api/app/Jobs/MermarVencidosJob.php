<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Caducidad\RevisorDeCaducidad;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Merma los lotes vencidos, en segundo plano.
 *
 * POR QUE UN JOB Y NO SOLO EL COMANDO
 * ----------------------------------
 * El comando horario puede quedar a medias si el contenedor se reinicia a
 * mitad de la corrida. El trabajo en la cola se reintenta solo, y el
 * `afterCommit` evita que corra antes de que exista el lote que tiene que
 * mermar.
 *
 * El trabajo NO lleva `$tenantId` porque la RLS exige un contexto y el job
 * corre sin sesion. El contexto lo pone `ContextoTenant` en cada panaderia
 * que este en la base.
 */
class MermarVencidosJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Tres reintentos con espera creciente.
     *
     * Si la base esta caida, tres intentos bastan: al cuarto el problema ya
     * no es temporal y lo importante es que alguien lo mire.
     */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly ?string $fecha = null) {}

    public function handle(RevisorDeCaducidad $revisor): void
    {
        $fecha = $this->fecha !== null ? \Illuminate\Support\Carbon::parse($this->fecha) : null;

        $marcados = $revisor->marcarVencidos($fecha);
        $resultado = $revisor->mermarVencidos($fecha);

        Log::info('Merma automatica de vencidos', [
            'marcados' => $marcados['marcados'],
            'mermados' => $resultado['mermados'],
            'omitidos' => $resultado['omitidos'],
            'sin_motivo' => $resultado['sin_motivo'] ?? false,
        ]);
    }
}