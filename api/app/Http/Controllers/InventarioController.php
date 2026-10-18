<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TipoMovimiento;
use App\Models\Articulo;
use App\Models\LoteProducto;
use App\Models\Ubicacion;
use App\Services\Caducidad\RevisorDeCaducidad;
use App\Services\Inventario\RegistroDeMovimiento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Inventario: saldos, lotes, caducidad y recepcion de compras.
 */
class InventarioController extends Controller
{
    public function __construct(
        private readonly RegistroDeMovimiento $movimientos,
        private readonly RevisorDeCaducidad $revisor,
    ) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Articulo::class);

        return response()->json(['articulos' => $this->movimientos->saldosDeArticulos()]);
    }

    /** Los lotes con stock, del que vence antes al que vence despues. */
    public function lotes(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Articulo::class);

        $lotes = LoteProducto::query()
            ->with(['articulo:id,nombre', 'ubicacion:id,nombre,tipo'])
            ->when($request->query('articulo_id'), fn ($q, $a) => $q->where('articulo_id', $a))
            ->when($request->boolean('solo_disponibles'), fn ($q) => $q->where('cantidad_disponible', '>', 0))
            // `NULLS LAST`: los lotes sin fecha de vencimiento NO vencen, y
            // van al final. Sin esto, PostgreSQL los pondria primero.
            ->orderByRaw('fecha_vencimiento ASC NULLS LAST')
            ->limit(300)
            ->get();

        return response()->json(['lotes' => $lotes->map(fn ($l) => $this->filaDeLote($l))]);
    }

    public function lote(LoteProducto $lote): JsonResponse
    {
        $this->authorize('viewAny', Articulo::class);

        return response()->json([
            'lote' => $this->filaDeLote($lote->load(['articulo', 'ubicacao', 'tanda'])),
            'movimientos' => $lote->movimientos()
                ->with(['motivoMerma:id,nombre', 'usuario:id,nombre'])
                ->orderBy('created_at')
                ->get()
                ->map(fn ($m) => [
                    'tipo' => $m->tipo->value,
                    'sentido' => $m->sentido->value,
                    'cantidad' => (float) $m->cantidad,
                    'motivo' => $m->motivoMerma?->nombre,
                    'referencia' => $m->referencia,
                    'fecha' => $m->created_at?->toIso8601String(),
                ]),
        ]);
    }

    /** Que se esta por vencer y que ya vencio. */
    public function caducando(): JsonResponse
    {
        $this->authorize('viewAny', Articulo::class);

        return response()->json($this->revisor->revisar());
    }

    /**
     * Recepcion de una compra.
     *
     * Un producto comprado entra AL MISMO inventario que uno horneado, y por
     * eso se puede dejar con lote: si la compra tiene vencimiento propio
     * (unques, un leche), hay que saberlo.
     *
     * @param  array<string, mixed>  $datos
     */
    public function recepcion(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Articulo::class);

        $validados = $request->validate([
            'articulo_id' => ['required', 'uuid', 'exists:articulos,id'],
            'cantidad' => ['required', 'numeric', 'gt:0'],
            'ubicacion_id' => ['nullable', 'uuid', 'exists:ubicaciones,id'],
            'codigo_lote' => ['nullable', 'string', 'max:48'],
            'fecha_vencimiento' => ['nullable', 'date'],
            'referencia' => ['nullable', 'string', 'max:64'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ]);

        $articulo = Articulo::query()->findOrFail($validados['articulo_id']);
        $usuario = $request->user();

        $lote = null;

        // Si la compra trae vencimiento, entra como lote: si no, queda como
        // stock a granel y no caduca.
        if (! empty($validados['fecha_vencimiento'])) {
            $ubicacionId = $validados['ubicacion_id']
                ?? Ubicacion::query()->value('id');

            if ($ubicacionId === null) {
                return response()->json([
                    'mensaje' => 'Falta la ubicacion donde se guarda la mercaderia recibida.',
                    'codigo' => 'sin_ubicacion',
                ], 422);
            }

            $lote = LoteProducto::query()->create([
                'tenant_id' => $usuario->tenant_id,
                'articulo_id' => $articulo->id,
                'ubicacion_id' => $ubicacionId,
                'tanda_id' => null,
                'codigo' => $validados['codigo_lote'] ?? ('COMPRA-' . Carbon::today()->format('Ymd')),
                'cantidad_inicial' => $validados['cantidad'],
                'cantidad_disponible' => $validados['cantidad'],
                'fecha_produccion' => Carbon::today()->toDateString(),
                'fecha_vencimiento' => Carbon::parse($validados['fecha_vencimiento'])->toDateString(),
                'estado' => \App\Enums\EstadoLote::DISPONIBLE,
            ]);
        }

        $movimiento = $this->movimientos->entrar(
            [
                'tenant_id' => $usuario->tenant_id,
                'articulo_id' => $articulo->id,
                'cantidad' => $validados['cantidad'],
                'ubicacion_id' => $validados['ubicacion_id'] ?? $lote?->ubicacion_id,
                'tipo' => TipoMovimiento::INGRESO,
                'referencia' => $validados['referencia'] ?? 'recepcion',
                'usuario_id' => $usuario->id,
                'notas' => $validados['notas'] ?? null,
            ],
            $lote
        );

        return response()->json([
            'movimiento_id' => $movimiento->id,
            'lote' => $lote === null ? null : $this->filaDeLote($lote->fresh(['articulo', 'ubicacion'])),
        ], 201);
    }

    private function filaDeLote(LoteProducto $lote): array
    {
        return [
            'id' => $lote->id,
            'codigo' => $lote->codigo,
            'articulo' => $lote->articulo?->nombre,
            'ubicacion' => $lote->ubicacion?->nombre,
            'tipo_ubicacion' => $lote->ubicacion?->tipo->value,
            'cantidad_inicial' => (float) $lote->cantidad_inicial,
            'cantidad_disponible' => (float) $lote->cantidad_disponible,
            'fecha_produccion' => $lote->fecha_produccion?->toDateString(),
            'fecha_vencimiento' => $lote->fecha_vencimiento?->toDateString(),
            'dias_para_vencer' => $lote->diasParaVencer(),
            'estado' => $lote->estado->value,
            'de_tanda' => $lote->tanda_id !== null,
        ];
    }
}