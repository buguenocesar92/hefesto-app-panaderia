<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\AgendarPedidoTortaRequest;
use App\Models\PedidoTorta;
use App\Services\Pedidos\GestorDePedidosDeTorta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Pedidos de torta: el encargo que hay que agendar.
 *
 * Es la venta de mayor valor del negocio y la que se pierde si no queda
 * anotada. Por eso la agenda es un endpoint de primera clase y no un filtro
 * mas del listado.
 */
class PedidoTortaController extends Controller
{
    public function __construct(private readonly GestorDePedidosDeTorta $gestor) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PedidoTorta::class);

        $pedidos = PedidoTorta::query()
            ->with(['articulo:id,nombre'])
            ->when($request->query('estado'), fn ($q, $e) => $q->where('estado', $e))
            ->orderBy('fecha_entrega')
            ->limit(200)
            ->get();

        return response()->json(['pedidos' => $pedidos->map(fn ($p) => $this->fila($p))]);
    }

    /**
     * La agenda: hoy, lo que viene y lo que ya se atrasó.
     *
     * Un pedido atrasado se muestra aparte y NO se marca como perdido: sigue
     * vigente. Marcarlo perdido automaticamente seria inventar un hecho que
     * todavia no se sabe.
     */
    public function agenda(): JsonResponse
    {
        $this->authorize('agenda', PedidoTorta::class);

        return response()->json($this->gestor->agenda(Carbon::today()));
    }

    public function store(AgendarPedidoTortaRequest $request): JsonResponse
    {
        $this->authorize('create', PedidoTorta::class);

        $pedido = $this->gestor->agendar(
            array_merge($request->datos(), ['creado_por_id' => $request->user()->id])
        );

        return response()->json(['pedido' => $this->fila($pedido->load('articulo'))], 201);
    }

    public function show(PedidoTorta $pedido): JsonResponse
    {
        $this->authorize('view', $pedido);

        return response()->json([
            'pedido' => $this->fila($pedido->load(['articulo', 'despacho', 'creadoPor'])),
        ]);
    }

    public function update(Request $request, PedidoTorta $pedido): JsonResponse
    {
        $this->authorize('update', $pedido);

        $datos = $request->validate([
            'cliente_nombre' => ['sometimes', 'string', 'max:160'],
            'cliente_contacto' => ['nullable', 'string', 'max:160'],
            'tamano' => ['nullable', 'string', 'max:64'],
            'sabor' => ['nullable', 'string', 'max:120'],
            'diseno' => ['nullable', 'string', 'max:4000'],
            'mensaje_personalizado' => ['nullable', 'string', 'max:1000'],
            'fecha_entrega' => ['sometimes', 'date'],
            'hora_entrega' => ['nullable', 'string', 'max:5'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ]);

        // Un pedido entregado o anulado no se edita: ya ocurrio, y cambiar
        // sus datos seria reescribir lo que paso.
        if (! $pedido->estado->admiteCambios()) {
            return response()->json([
                'mensaje' => "Un pedido en estado {$pedido->estado->value} ya no se puede editar.",
                'codigo' => 'estado_invalido',
            ], 422);
        }

        $pedido->fill($datos);
        $pedido->save();

        return response()->json(['pedido' => $this->fila($pedido->fresh('articulo'))]);
    }

    /** Confirma el pedido. Exige anticipo. */
    public function confirmar(PedidoTorta $pedido): JsonResponse
    {
        $this->authorize('confirmar', $pedido);

        return response()->json(['pedido' => $this->fila($this->gestor->confirmar($pedido))]);
    }

    /** Suma un anticipo. */
    public function anticipo(Request $request, PedidoTorta $pedido): JsonResponse
    {
        $this->authorize('anticipo', $pedido);

        $datos = $request->validate([
            'monto' => ['required', 'numeric', 'gt:0'],
        ]);

        return response()->json([
            'pedido' => $this->fila($this->gestor->agregarAnticipo($pedido, (float) $datos['monto'])),
        ]);
    }

    /** Despacha: descuenta la torta del lote y marca el pedido entregado. */
    public function despachar(Request $request, PedidoTorta $pedido): JsonResponse
    {
        $this->authorize('despachar', $pedido);

        $datos = $request->validate([
            'direccion' => ['nullable', 'string', 'max:2000'],
            'entregado_a' => ['nullable', 'string', 'max:160'],
            'lote_producto_id' => ['nullable', 'uuid', 'exists:lote_productos,id'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ]);

        $despacho = $this->gestor->despachar(
            $pedido,
            array_merge($datos, ['usuario_id' => $request->user()->id])
        );

        return response()->json([
            'despacho' => [
                'id' => $despacho->id,
                'fecha' => $despacho->fecha?->toDateString(),
                'entregado_a' => $despacho->entregado_a,
            ],
            'pedido' => $this->fila($pedido->fresh('articulo')),
        ]);
    }

    public function anular(PedidoTorta $pedido): JsonResponse
    {
        $this->authorize('anular', $pedido);

        return response()->json(['pedido' => $this->fila($this->gestor->anular($pedido))]);
    }

    private function fila(PedidoTorta $pedido): array
    {
        return [
            'id' => $pedido->id,
            'numero' => $pedido->numero,
            'cliente_nombre' => $pedido->cliente_nombre,
            'cliente_contacto' => $pedido->cliente_contacto,
            'articulo' => $pedido->articulo?->nombre,
            'cantidad' => $pedido->cantidad,
            'tamano' => $pedido->tamano,
            'sabor' => $pedido->sabor,
            'diseno' => $pedido->diseno,
            'fecha_entrega' => $pedido->fecha_entrega?->toDateString(),
            'hora_entrega' => $pedido->hora_entrega,
            'estado' => $pedido->estado->value,
            'monto_total' => (float) $pedido->monto_total,
            'anticipo' => (float) $pedido->anticipo,
            'saldo' => (float) $pedido->saldo,
            'atrasado' => $pedido->estaAtrasado(),
            'despachado' => $pedido->relationLoaded('despacho') ? $pedido->despacho !== null : false,
        ];
    }
}