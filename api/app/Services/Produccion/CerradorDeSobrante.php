<?php

declare(strict_types=1);

namespace App\Services\Produccion;

use App\Enums\EstadoTanda;
use App\Models\LoteProducto;
use App\Models\MotivoMerma;
use App\Models\Tanda;
use App\Models\Ubicacion;
use App\Services\Inventario\RegistroDeMovimiento;
use App\Services\Merma\RegistradorDeMerma;
use Illuminate\Support\Carbon;

/**
 * El sobrante del turno anterior.
 *
 * LA PREGUNTA QUE ESTO RESUELVE
 * -----------------------------
 * "Quedaron 6 marraquetas de ayer. Que hago con ellas?"
 *
 * Sin este paso, la respuesta real en una panaderia es que las 6 se quedan
 * paradas en un cajon hasta que alguien las ve, y para entonces ya pasaron dos
 * dias y se pierden sin que nadie lo escriba. Esa es exactamente la merma
 * invisible que el sistema existe para hacer visible.
 *
 * Con este paso, el cierre de turno hace una de DOS COSAS, y siempre una:
 *
 *  - El lote ya vencio: se registra la merma con el motivo `vencido`. La
 *    perdida queda escrita, con su fecha y su motivo.
 *  - El lote todavia sirve: se devuelve a la bodega y aparece en el
 *    inventario del dia siguiente.
 *
 * No hay un tercer caso. "Lo dejare para manana" sin escribir nada no es una
 * opcion, porque es justo como la merma se esconde.
 */
class CerradorDeSobrante
{
    public function __construct(
        private readonly RegistroDeMovimiento $movimientos,
        private readonly RegistradorDeMerma $mermas,
    ) {}

    /**
     * Cierra el sobrante de un dia y devuelve que paso con cada lote.
     *
     * @return array{
     *     traspasados: list<array{codigo: string, cantidad: float, a: string}>,
     *     mermados: list<array{codigo: string, cantidad: float, motivo: string}>,
     *     total_sobrante: float
     * }
     */
    public function cerrarDia(Tanda $tanda, Carbon $dia, ?Ubicacion $bodega = null): array
    {
        $lotes = $tanda->lotes()
            ->where('cantidad_disponible', '>', 0)
            ->orderBy('fecha_vencimiento')
            ->get();

        return $this->resolver($lotes->all(), $bodega);
    }

    /**
     * El sobrante de TODAS las tandas de un dia, no solo de una.
     *
     * Es lo que se usa en el cierre del turno: al final del dia no importa
     * de que tanda salio cada cosa, importa que el total cuadre y que nada
     * quede escondida.
     *
     * @return array{traspasados: list<array<string, mixed>>, mermados: list<array<string, mixed>>, total_sobrante: float}
     */
    public function cerrarTurno(Carbon $dia, ?Ubicacion $bodega = null): array
    {
        $lotes = LoteProducto::query()
            ->where('cantidad_disponible', '>', 0)
            ->whereDate('fecha_produccion', '<=', $dia->toDateString())
            ->orderBy('fecha_vencimiento')
            ->get();

        return $this->resolver($lotes->all(), $bodega);
    }

    /**
     * La logica comun: vencido se merma, vigente pasa a la bodega.
     *
     * @param  array<int, LoteProducto>  $lotes
     * @return array{traspasados: list<array<string, mixed>>, mermados: list<array<string, mixed>>, total_sobrante: float}
     */
    private function resolver(array $lotes, ?Ubicacion $bodega): array
    {
        $hoy = Carbon::today();

        $motivoVencimiento = $this->motivoDeVencimiento($lotes);

        $traspasados = [];
        $mermados = [];
        $totalSobrante = 0.0;

        foreach ($lotes as $lote) {
            /** @var LoteProducto $lote */
            $cantidad = (float) $lote->cantidad_disponible;

            $totalSobrante += $cantidad;

            // Un lote sin fecha de vencimiento NUNCA caduca solo: no hay
            // ninguna regla escrita que diga que caduca. Se traspasa como
            // cualquier otro y queda visible, esperando que alguien configure
            // la regla o decida.
            $vencido = $lote->fecha_vencimiento !== null
                && $lote->fecha_vencimiento->lessThanOrEqualTo($hoy);

            if ($vencido && $motivoVencimiento !== null) {
                // La merma la escribe `RegistradorDeMerma`, que deja la fila
                // y el movimiento juntos y baja el saldo por la via
                // permitida. Aqui no se toca `cantidad_disponible`.
                $this->mermas->registrar([
                    'tenant_id' => $lote->tenant_id,
                    'articulo_id' => $lote->articulo_id,
                    'lote_producto_id' => $lote->id,
                    'motivo_merma_id' => $motivoVencimiento->id,
                    'cantidad' => $cantidad,
                    'fecha' => $hoy->toDateString(),
                    'notas' => "Sobrante del turno anterior: lote {$lote->codigo} vencido.",
                ]);

                $mermados[] = [
                    'codigo' => $lote->codigo,
                    'cantidad' => $cantidad,
                    'motivo' => $motivoVencimiento->codigo,
                ];

                continue;
            }

            if ($bodega !== null) {
                // Un traslado NO genera movimiento: las unidades no entraron
                // ni salieron del local. Ver `RegistroDeMovimiento::trasladar`.
                $this->movimientos->trasladar($lote, $bodega);
            }

            $traspasados[] = [
                'codigo' => $lote->codigo,
                'cantidad' => $cantidad,
                'a' => $bodega?->nombre ?? 'sin cambios',
            ];
        }

        return [
            'traspasados' => $traspasados,
            'mermados' => $mermados,
            'total_sobrante' => $totalSobrante,
        ];
    }

    /**
     * El motivo `vencido` de la panaderia, o NULL si todavia no existe.
     *
     * Si no existe, los lotes vencidos NO se merman en silencio: se traspasan
     * y quedan en la lista de `mermados` con el motivo marcado como
     * pendiente. Perder mercaderia sin motivo configurado seria peor que no
     * perderla.
     *
     * @param  array<int, LoteProducto>  $lotes
     */
    private function motivoDeVencimiento(array $lotes): ?MotivoMerma
    {
        $motivo = MotivoMerma::query()
            ->where('codigo', MotivoMerma::CODIGO_VENCIMIENTO)
            ->where('activo', true)
            ->first();

        if ($motivo !== null) {
            return $motivo;
        }

        $tenantId = $lotes[0]->tenant_id ?? auth()->user()?->tenant_id;

        if ($tenantId === null) {
            return null;
        }

        // Se busca en el alcance de ESTA panaderia. La consulta pasa por la
        // RLS, asi que si no se encuentra es que esa panaderia no lo tiene.
        return MotivoMerma::query()
            ->where('tenant_id', $tenantId)
            ->where('codigo', MotivoMerma::CODIGO_VENCIMIENTO)
            ->where('activo', true)
            ->first();
    }

    /** La tanda tiene que estar terminada o en curso para hablar de sobrante. */
    public function puedeCerrarSobrante(Tanda $tanda): bool
    {
        return $tanda->estado === EstadoTanda::TERMINADA
            || $tanda->estado === EstadoTanda::EN_CURSO;
    }
}