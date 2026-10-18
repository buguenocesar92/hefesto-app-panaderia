<?php

declare(strict_types=1);

namespace App\Services\Merma;

use App\Enums\EstadoLote;
use App\Enums\OrigenMerma;
use App\Enums\TipoMovimiento;
use App\Exceptions\ErrorDePanaderia;
use App\Models\LoteProducto;
use App\Models\Merma;
use App\Models\MotivoMerma;
use App\Services\Inventario\RegistroDeMovimiento;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Registra una perdida de mercaderia.
 *
 * LA REGLA INNEGOCIABLE
 * ---------------------
 * Una merma SIEMPRE deja dos cosas escritas en la misma transaccion:
 *
 *  1. La fila en `mermas`, con su motivo obligatorio.
 *  2. El movimiento de salida, que descuenta el stock.
 *
 * No hay forma de registrar una perdida que no reste, ni una salida de stock
 * por perdida que no quede en `mermas`. Por eso el metodo no acepta un
 * motivo vacio: una perdida sin explicacion es justo el defecto que este
 * sistema tiene que impedir.
 */
class RegistradorDeMerma
{
    public function __construct(private readonly RegistroDeMovimiento $movimientos) {}

    /**
     * @param  array{
     *     articulo_id: string,
     *     lote_producto_id?: string|null,
     *     motivo_merma_id: string,
     *     cantidad: float,
     *     fecha?: string|null,
     *     origen?: OrigenMerma,
     *     usuario_id?: string|null,
     *     notas?: string|null
     * }  $datos
     */
    public function registrar(array $datos): Merma
    {
        if (empty($datos['motivo_merma_id'])) {
            throw ErrorDePanaderia::mermaSinMotivo();
        }

        $cantidad = (float) $datos['cantidad'];

        if ($cantidad <= 0) {
            throw new ErrorDePanaderia(
                'La cantidad de la merma tiene que ser mayor que cero.',
                'cantidad_invalida'
            );
        }

        $motivo = MotivoMerma::query()->findOrFail($datos['motivo_merma_id']);

        if (! $motivo->activo) {
            // Un motivo dado de baja no puede seguir explicando mermas: si
            // el informe agrupa por motivo, "quemado" tiene que significar la
            // misma cosa el lunes que el viernes.
            throw new ErrorDePanaderia(
                "El motivo {$motivo->nombre} esta desactivado y no puede explicar una merma nueva.",
                'motivo_inactivo'
            );
        }

        $lote = null;

        if (! empty($datos['lote_producto_id'])) {
            $lote = LoteProducto::query()->findOrFail($datos['lote_producto_id']);

            if ($lote->estado === EstadoLote::MERMADO) {
                throw ErrorDePanaderia::loteAgotado($lote->codigo);
            }

            if ($lote->cantidad_disponible + 0.000001 < $cantidad) {
                throw ErrorDePanaderia::loteAgotado($lote->codigo);
            }
        }

        $tenantId = $lote?->tenant_id ?? auth()->user()?->tenant_id;

        if ($tenantId === null) {
            throw new ErrorDePanaderia(
                'No se puede registrar una merma sin saber a que panaderia pertenece.',
                'sin_empresa'
            );
        }

        $origen = $datos['origen'] ?? OrigenMerma::MANUAL;
        $fecha = isset($datos['fecha']) ? Carbon::parse($datos['fecha']) : Carbon::today();

        return DB::transaction(function () use ($datos, $cantidad, $motivo, $lote, $tenantId, $origen, $fecha): Merma {
            $merma = Merma::query()->create([
                'tenant_id' => $tenantId,
                'articulo_id' => $datos['articulo_id'],
                'lote_producto_id' => $lote?->id,
                'motivo_merma_id' => $motivo->id,
                'cantidad' => $cantidad,
                'fecha' => $fecha->toDateString(),
                'origen' => $origen,
                // La merma automatica no tiene a nadie detras: es el trabajo
                // programado. Se deja en NULL y no se disfraza de manual.
                'usuario_id' => $origen->esDePersona()
                    ? ($datos['usuario_id'] ?? auth()->id())
                    : null,
                'notas' => $datos['notas'] ?? null,
            ]);

            // El movimiento lleva el motivo tambien. Asi una consulta al libro
            // de inventario puede explicar la salida sin tener que unir con
            // `mermas`, que es lo que necesita el informe cuando se mira
            // "todo lo que salio y por que".
            $this->movimientos->salir(
                array_merge(
                    [
                        'tenant_id' => $tenantId,
                        'articulo_id' => $datos['articulo_id'],
                        'cantidad' => $cantidad,
                        'tipo' => TipoMovimiento::SALIDA,
                        'motivo_merma_id' => $motivo->id,
                        'usuario_id' => $datos['usuario_id'] ?? auth()->id(),
                    ],
                    $lote !== null ? ['lote_producto_id' => $lote->id] : []
                )
            );

            return $merma->fresh();
        });
    }

    /**
     * Merma de UN lote entero, que es lo que hace el trabajo programado con
     * los lotes vencidos.
     */
    public function mermarLoteCompleto(LoteProducto $lote, MotivoMerma $motivo, ?string $usuarioId = null): Merma
    {
        return $this->registrar([
            'tenant_id' => $lote->tenant_id,
            'articulo_id' => $lote->articulo_id,
            'lote_producto_id' => $lote->id,
            'motivo_merma_id' => $motivo->id,
            'cantidad' => (float) $lote->cantidad_disponible,
            'origen' => OrigenMerma::AUTOMATICA,
            'usuario_id' => $usuarioId,
            'notas' => "Lote {$lote->codigo} vencido el {$lote->fecha_vencimiento}.",
        ]);
    }
}