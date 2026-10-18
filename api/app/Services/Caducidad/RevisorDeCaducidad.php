<?php

declare(strict_types=1);

namespace App\Services\Caducidad;

use App\Enums\EstadoLote;
use App\Models\LoteProducto;
use App\Models\MotivoMerma;
use App\Services\Merma\RegistradorDeMerma;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Revisa que lotes se estan por vencer o ya vencieron.
 *
 * POR QUE ESTO NO ES SOLO UN "AVISO DE CADUCIDAD"
 * ----------------------------------------------
 * En una panaderia el problema no es avisar que el pan caduca: es que el pan
 * que caduca NO SE VENDA. Si el sistema solo avisa, el aviso queda en una
 * pantalla que nadie mira y el lote sigue ahi.
 *
 * Por eso la revision tiene dos efectos:
 *
 *  1. Marca el lote como `vencido`, que ya NO se puede vender en el mostrador.
 *     Esta es la parte que evita el peor defecto: vender pan vencido.
 *  2. Si la panaderia dejo la merma automatica activa para ese producto, deja
 *     la merma escrita con su motivo.
 *
 * Y si la merma automatica NO esta activa, el lote queda vencido y visible,
 * esperando a que una persona decida. Un producto sin reglaConfigured no se
 * inventa: se pregunta.
 */
class RevisorDeCaducidad
{
    public function __construct(private readonly RegistradorDeMerma $mermas) {}

    /**
     * Los lotes que vencen dentro de la ventana de aviso, y los que ya
     * vencieron.
     *
     * @return array{por_vencer: array<int, mixed>, vencidos: array<int, mixed>}
     */
    public function revisar(?Carbon $hoy = null, ?int $diasAviso = null): array
    {
        $hoy ??= Carbon::today();
        $diasAviso ??= (int) config('panaderia.caducidad.dias_aviso');

        $limite = $hoy->copy()->addDays($diasAviso);

        $lotes = LoteProducto::query()
            ->with(['articulo.vigencia'])
            ->where('cantidad_disponible', '>', 0)
            ->whereNotNull('fecha_vencimiento')
            ->whereDate('fecha_vencimiento', '<=', $limite->toDateString())
            ->orderBy('fecha_vencimiento')
            ->get();

        $porVencer = [];
        $vencidos = [];

        foreach ($lotes as $lote) {
            /** @var LoteProducto $lote */
            $fila = [
                'lote_id' => $lote->id,
                'codigo' => $lote->codigo,
                'articulo' => $lote->articulo?->nombre,
                'cantidad_disponible' => (float) $lote->cantidad_disponible,
                'fecha_vencimiento' => $lote->fecha_vencimiento?->toDateString(),
                'dias' => $lote->diasParaVencer($hoy),
            ];

            if ($lote->fecha_vencimiento->lessThanOrEqualTo($hoy)) {
                $vencidos[] = $fila;
            } else {
                $porVencer[] = $fila;
            }
        }

        return [
            'por_vencer' => $porVencer,
            'vencidos' => $vencidos,
        ];
    }

    /**
     * Marca como vencidos los lotes que ya pasaron la fecha.
     *
     * El estado se cambia por la via del movimiento, porque `estado` esta
     * entre las columnas protegidas del gatillo: cambiarlo "a mano" no es
     * una operacion, es una exception.
     *
     * @return array{marcados: int, ids: array<int, string>}
     */
    public function marcarVencidos(?Carbon $hoy = null): array
    {
        $hoy ??= Carbon::today();

        $lotes = LoteProducto::query()
            ->where('cantidad_disponible', '>', 0)
            ->whereNotNull('fecha_vencimiento')
            ->whereDate('fecha_vencimiento', '<=', $hoy->toDateString())
            ->where('estado', EstadoLote::DISPONIBLE)
            ->get();

        $ids = [];

        DB::transaction(function () use ($lotes, &$ids): void {
            foreach ($lotes as $lote) {
                /** @var LoteProducto $lote */
                DB::statement("SELECT set_config('app.movimiento_inventario', '1', true)");

                $lote->estado = EstadoLote::VENCIDO;
                $lote->save();

                $ids[] = $lote->id;
            }
        });

        return [
            'marcados' => count($ids),
            'ids' => $ids,
        ];
    }

    /**
     * Registra la merma de los lotes vencidos que la panaderia tiene marcada
     * como automatica.
     *
     * @return array{mermados: int, omitidos: int, detalle: array<int, array{codeigo: string, motivo: string}>}
     */
    public function mermarVencidos(?Carbon $hoy = null, ?string $usuarioId = null): array
    {
        $hoy ??= Carbon::today();

        $motivo = $this->motivoDeVencimiento();

        if ($motivo === null) {
            // Sin motivo `vencido` en el catalogo, no se merma nada: perder
            // mercaderia sin motivo escrito seria peor que no perderla.
            return [
                'mermados' => 0,
                'omitidos' => 0,
                'detalle' => [],
                'sin_motivo' => true,
            ];
        }

        $lotes = LoteProducto::query()
            ->with(['articulo.vigencia'])
            ->where('cantidad_disponible', '>', 0)
            ->whereNotNull('fecha_vencimiento')
            ->whereDate('fecha_vencimiento', '<=', $hoy->toDateString())
            ->whereIn('estado', [EstadoLote::DISPONIBLE, EstadoLote::VENCIDO])
            ->get();

        $mermados = 0;
        $omitidos = 0;
        $detalle = [];

        foreach ($lotes as $lote) {
            /** @var LoteProducto $lote */
            if (! $this->mermaAutomaticaDe($lote)) {
                // El administrador dejo la decision en manos de una persona.
                $omitidos++;
                $detalle[] = ['codigo' => $lote->codigo, 'motivo' => 'merma_automatica_desactivada'];

                continue;
            }

            $this->mermas->mermarLoteCompleto($lote, $motivo, $usuarioId);

            $mermados++;
            $detalle[] = ['codigo' => $lote->codigo, 'motivo' => $motivo->codigo];
        }

        return [
            'mermados' => $mermados,
            'omitidos' => $omitidos,
            'detalle' => $detalle,
            'sin_motivo' => false,
        ];
    }

    private function mermaAutomaticaDe(LoteProducto $lote): bool
    {
        $vigencia = $lote->articulo?->relationLoaded('vigencia')
            ? $lote->articulo->vigencia
            : null;

        return (bool) ($vigencia?->merma_automatica ?? false);
    }

    private function motivoDeVencimiento(): ?MotivoMerma
    {
        return MotivoMerma::query()
            ->where('codigo', MotivoMerma::CODIGO_VENCIMIENTO)
            ->where('activo', true)
            ->first();
    }
}