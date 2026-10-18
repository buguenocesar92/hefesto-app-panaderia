<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\MetodoPago;
use App\Http\Requests\AbrirVentaRequest;
use App\Models\Venta;
use App\Services\Ventas\RegistradorDeVenta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Venta de mostrador.
 *
 * La venta se ABRE (y el pan sale del almacen) y despues se COBRA. Abrirla
 * antes de cobrar es lo que refleja que la mercaderia salio aunque el pago
 * este pendiente.
 */
class VentaController extends Controller
{
    public function __construct(private readonly RegistradorDeVenta $registrador) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Venta::class);

        $ventas = Venta::query()
            ->with(['items.articulo:id,nombre', 'pagos', 'vendedor:id,nombre'])
            ->when($request->query('fecha'), fn ($q, $f) => $q->whereDate('fecha', $f))
            ->when($request->query('estado'), fn ($q, $e) => $q->where('estado', $e))
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return response()->json(['ventas' => $ventas->map(fn ($v) => $this->fila($v))]);
    }

    public function store(AbrirVentaRequest $request): JsonResponse
    {
        $this->authorize('create', Venta::class);

        $venta = $this->registrador->abrir(
            array_merge($request->datos(), ['usuario_id' => $request->user()->id])
        );

        return response()->json(['venta' => $this->fila($venta)], 201);
    }

    public function show(Venta $venta): JsonResponse
    {
        $this->authorize('view', $venta);

        return response()->json([
            'venta' => $this->fila($venta->load(['items.articulo', 'items.lote', 'pagos', 'vendedor'])),
        ]);
    }

    /** Cobra y cierra la venta. */
    public function pagar(Request $request, Venta $venta): JsonResponse
    {
        $this->authorize('cobrar', $venta);

        $datos = $request->validate([
            'metodo' => ['required', 'string', 'in:' . implode(',', array_map(
                fn (MetodoPago $m) => $m->value,
                MetodoPago::cases()
            ))],
            // NULL significa "cobra el total de la venta".
            'monto' => ['nullable', 'numeric', 'gt:0'],
            'recibido' => ['nullable', 'numeric', 'gt:0'],
        ]);

        $cobrada = $this->registrador->cobrar($venta, [
            'metodo' => MetodoPago::from($datos['metodo']),
            'monto' => $datos['monto'] ?? null,
            'recibido' => $datos['recibido'] ?? null,
        ]);

        return response()->json(['venta' => $this->fila($cobrada)]);
    }

    /**
     * Anula una venta ABIERTA y devuelve la mercaderia al lote.
     *
     * Una venta ya cobrada no se anula desde aca: se devuelve el dinero y se
     * registra el retiro. Ver `RegistradorDeVenta::anular`.
     */
    public function anular(Venta $venta): JsonResponse
    {
        $this->authorize('anular', $venta);

        return response()->json(['venta' => $this->fila($this->registrador->anular($venta))]);
    }

    private function fila(Venta $venta): array
    {
        return [
            'id' => $venta->id,
            'numero' => $venta->numero,
            'estado' => $venta->estado->value,
            'fecha' => $venta->fecha?->toDateString(),
            'bruto' => (float) $venta->bruto,
            'descuento' => (float) $venta->descuento,
            'total' => (float) $venta->total,
            'lote_cierre_id' => $venta->lote_cierre_id,
            'minutos_abierta' => $venta->minutosAbierta(),
            'items' => $venta->relationLoaded('items')
                ? $venta->items->map(fn ($i) => [
                    'articulo' => $i->articulo?->nombre,
                    'lote' => $i->lote?->codigo,
                    'cantidad' => (float) $i->cantidad,
                    'precio_unitario' => (float) $i->precio_unitario,
                    'subtotal' => (float) $i->subtotal,
                ])->all()
                : [],
            'pagos' => $venta->relationLoaded('pagos')
                ? $venta->pagos->map(fn ($p) => [
                    'metodo' => $p->metodo->value,
                    'monto' => (float) $p->monto,
                    'recibido' => $p->recibido === null ? null : (float) $p->recibido,
                    'vuelto' => $p->vuelto === null ? null : (float) $p->vuelto,
                ])->all()
                : [],
        ];
    }
}