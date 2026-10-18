<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\RegistrarMermaRequest;
use App\Models\Merma;
use App\Models\MotivoMerma;
use App\Services\Merma\RegistradorDeMerma;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mermas: la perdida real de la panaderia.
 *
 * Registrar una merma es de `hornero` y `encargado`. Ver el INFORME es solo
 * de `encargado` y `administrador`: el informe dice cuantos pesos perdio el
 * negocio, y no es un dato de todos.
 */
class MermaController extends Controller
{
    public function __construct(private readonly RegistradorDeMerma $registrador) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Merma::class);

        $mermas = Merma::query()
            ->with(['articulo:id,nombre,precio_venta', 'motivo:id,codigo,nombre', 'usuario:id,nombre', 'lote:id,codigo'])
            ->when($request->query('desde'), fn ($q, $f) => $q->whereDate('fecha', '>=', $f))
            ->when($request->query('hasta'), fn ($q, $f) => $q->whereDate('fecha', '<=', $f))
            ->when($request->query('articulo_id'), fn ($q, $a) => $q->where('articulo_id', $a))
            ->orderByDesc('fecha')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        return response()->json([
            'mermas' => $mermas->map(fn ($m) => [
                'id' => $m->id,
                'articulo' => $m->articulo?->nombre,
                'lote' => $m->lote?->codigo,
                'motivo' => $m->motivo?->nombre,
                'cantidad' => (float) $m->cantidad,
                'valor_perdido' => $m->valorPerdido(),
                'fecha' => $m->fecha?->toDateString(),
                // `manual` o `automatica`. Permite separar la perdida que
                // vino del reloj de la que vino de las manos.
                'origen' => $m->origen->value,
                'usuario' => $m->usuario?->nombre,
                'notas' => $m->notas,
            ]),
        ]);
    }

    /**
     * Registra una perdida.
     *
     * El motivo es obligatorio: sin el, la perdida queda escrita pero no
     * explicada, que es lo mismo que no registrarla.
     */
    public function store(RegistrarMermaRequest $request): JsonResponse
    {
        $this->authorize('create', Merma::class);

        $merma = $this->registrador->registrar(
            array_merge($request->datos(), ['usuario_id' => $request->user()->id])
        );

        return response()->json(['merma' => $this->fila($merma)], 201);
    }

    /** Los motivos disponibles. */
    public function motivos(): JsonResponse
    {
        $this->authorize('viewAny', Merma::class);

        $motivos = MotivoMerma::query()
            ->where('activo', true)
            ->orderBy('nombre')
            ->get();

        return response()->json([
            'motivos' => $motivos->map(fn ($m) => [
                'id' => $m->id,
                'codigo' => $m->codigo,
                'nombre' => $m->nombre,
                'descripcion' => $m->descripcion,
                'merma_por_vencimiento' => $m->merma_por_vencimiento,
            ]),
        ]);
    }

    private function fila(Merma $merma): array
    {
        return [
            'id' => $merma->id,
            'articulo_id' => $merma->articulo_id,
            'lote_producto_id' => $merma->lote_producto_id,
            'motivo_merma_id' => $merma->motivo_merma_id,
            'cantidad' => (float) $merma->cantidad,
            'fecha' => $merma->fecha?->toDateString(),
            'origen' => $merma->origen->value,
        ];
    }
}