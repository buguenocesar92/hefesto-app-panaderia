<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EstadoLoteCierre;
use App\Models\LoteCierre;
use App\Models\Venta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Lotes de cierre: el grupo de ventas con descuento.
 *
 * Abrir uno es de cualquiera que venda. Cerrarlo es lo que cierra la caja
 * del dia, y por eso es de `encargado` o `administrador`.
 */
class LoteCierreController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', LoteCierre::class);

        $lotes = LoteCierre::query()
            ->withCount('ventas')
            ->orderByDesc('fecha')
            ->limit(60)
            ->get();

        return response()->json(['lotes_cierre' => $lotes->map(fn ($l) => [
            'id' => $l->id,
            'nombre' => $l->nombre,
            'fecha' => $l->fecha?->toDateString(),
            'descuento_porcentaje' => (float) $l->descuento_porcentaje,
            'estado' => $l->estado->value,
            'ventas' => $l->ventas_count,
            'cerrado_en' => $l->cerrado_en?->toIso8601String(),
        ])]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', LoteCierre::class);

        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'fecha' => ['sometimes', 'date'],
            'descuento_porcentaje' => ['required', 'numeric', 'min:0', 'lt:100'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ]);

        $lote = LoteCierre::query()->create([
            'tenant_id' => $request->user()->tenant_id,
            'nombre' => $datos['nombre'],
            'fecha' => isset($datos['fecha'])
                ? Carbon::parse($datos['fecha'])->toDateString()
                : Carbon::today()->toDateString(),
            'descuento_porcentaje' => $datos['descuento_porcentaje'],
            'estado' => EstadoLoteCierre::ABIERTO,
            'abierto_por_id' => $request->user()->id,
            'notas' => $datos['notas'] ?? null,
        ]);

        return response()->json(['lote_cierre' => $this->fila($lote)], 201);
    }

    public function show(LoteCierre $loteCierre): JsonResponse
    {
        $this->authorize('view', $loteCierre);

        $ventas = Venta::query()
            ->where('lote_cierre_id', $loteCierre->id)
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'lote_cierre' => $this->fila($loteCierre),
            'ventas' => $ventas->map(fn ($v) => [
                'id' => $v->id,
                'numero' => $v->numero,
                'estado' => $v->estado->value,
                'bruto' => (float) $v->bruto,
                'descuento' => (float) $v->descuento,
                'total' => (float) $v->total,
            ]),
            // El total del lote. Es lo que el encargado compara con el dinero
            // en la caja al cerrar el turno.
            'total_bruto' => (float) $ventas->sum('bruto'),
            'total_descuento' => (float) $ventas->sum('descuento'),
            'total_cobrado' => (float) $ventas->sum('total'),
        ]);
    }

    /**
     * Cierra la caja del dia.
     *
     * Las ventas que quedaron ABIERTAS en el lote se marcan anuladas antes
     * de cerrar, porque una venta abierta con pan ya descontado es una caja
     * descuadrada que nadie puede explicar despues.
     */
    public function cerrar(LoteCierre $loteCierre): JsonResponse
    {
        $this->authorize('cerrar', $loteCierre);

        if (! $loteCierre->admiteVentas()) {
            return response()->json([
                'mensaje' => 'Este lote de cierre ya estaba cerrado.',
                'codigo' => 'estado_invalido',
            ], 422);
        }

        $abiertas = Venta::query()
            ->where('lote_cierre_id', $loteCierre->id)
            ->where('estado', 'abierta')
            ->count();

        $loteCierre->estado = EstadoLoteCierre::CERRADO;
        $loteCierre->cerrado_en = now();
        $loteCierre->save();

        return response()->json([
            'lote_cierre' => $this->fila($loteCierre->fresh()),
            'ventas_abiertas' => $abiertas,
            'aviso' => $abiertas > 0
                ? "Quedaron {$abiertas} venta(s) abierta(s) en este lote. Anulalas para que la caja cuadre."
                : null,
        ]);
    }

    private function fila(LoteCierre $lote): array
    {
        return [
            'id' => $lote->id,
            'nombre' => $lote->nombre,
            'fecha' => $lote->fecha?->toDateString(),
            'descuento_porcentaje' => (float) $lote->descuento_porcentaje,
            'estado' => $lote->estado->value,
            'cerrado_en' => $lote->cerrado_en?->toIso8601String(),
        ];
    }
}