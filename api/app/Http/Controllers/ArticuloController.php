<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\GuardarArticuloRequest;
use App\Models\Articulo;
use App\Services\Inventario\RegistroDeMovimiento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ArticuloController extends Controller
{
    public function __construct(private readonly RegistroDeMovimiento $movimientos) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Articulo::class);

        $consulta = Articulo::query()
            ->with(['unidadBase', 'vigencia'])
            ->when($request->query('q'), function ($q, $termino) {
                $q->where(function ($interno) use ($termino) {
                    $interno->where('nombre', 'ilike', "%{$termino}%")
                        ->orWhere('sku', 'ilike', "%{$termino}%");
                });
            })
            // Por omision, solo lo que esta activo: un catalogo lleno de
            // articulos dados de baja esconde los que sirven.
            ->when($request->boolean('incluir_inactivos') === false, fn ($q) => $q->where('activo', true))
            ->when($request->boolean('solo_vendibles'), fn ($q) => $q->where('se_vende', true))
            ->orderBy('nombre');

        return response()->json(['articulos' => $consulta->get()->map(fn ($a) => $this->fila($a))]);
    }

    public function store(GuardarArticuloRequest $request): JsonResponse
    {
        $this->authorize('create', Articulo::class);

        $articulo = new Articulo($request->datos());
        $articulo->tenant_id = $request->user()->tenant_id;
        $articulo->precio_venta = $request->validated('precio_venta');
        $articulo->stock_minimo = $request->validated('stock_minimo');
        $articulo->save();

        return response()->json(['articulo' => $this->fila($articulo->fresh())], 201);
    }

    /** Los que estan en o por debajo del minimo. */
    public function bajoMinimo(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Articulo::class);

        // La comparacion se hace sobre el SALDO ledido del libro de
        // movimientos, no contra una columna de stock que puede quedar
        // desactualizada.
        $saldos = $this->movimientos->saldosDeArticulos();

        $bajoMinimo = [];

        foreach ($saldos as $saldo) {
            $minimo = $saldo['stock_minimo'];

            if ($minimo === null) {
                continue;
            }

            if ($saldo['saldo'] <= $minimo) {
                $bajoMinimo[] = [
                    'articulo_id' => $saldo['articulo_id'],
                    'nombre' => $saldo['nombre'],
                    'sku' => $saldo['sku'],
                    'saldo' => $saldo['saldo'],
                    'stock_minimo' => $minimo,
                    'faltan' => round($minimo - $saldo['saldo'], 6),
                ];
            }
        }

        return response()->json(['articulos' => $bajoMinimo]);
    }

    public function show(Articulo $articulo): JsonResponse
    {
        $this->authorize('view', $articulo);

        return response()->json(['articulo' => $this->fila($articulo)]);
    }

    public function update(GuardarArticuloRequest $request, Articulo $articulo): JsonResponse
    {
        $this->authorize('update', $articulo);

        $articulo->fill($request->datos());
        $articulo->precio_venta = $request->validated('precio_venta');
        $articulo->stock_minimo = $request->validated('stock_minimo');
        $articulo->save();

        return response()->json(['articulo' => $this->fila($articulo->fresh())]);
    }

    /**
     * Desactiva un articulo.
     *
     * No se borra: un articulo con ventas y movimientos encima no se puede
     * borrar sin perder la historia. Se da de baja, que es lo que hace de
     * verdad una panaderia cuando deja de ofrecer algo.
     */
    public function desactivar(Articulo $articulo): JsonResponse
    {
        $this->authorize('desactivar', $articulo);

        $articulo->activo = false;
        $articulo->save();

        return response()->json(['articulo' => $this->fila($articulo->fresh())]);
    }

    /** El libro de movimientos de un articulo: su historia completa. */
    public function movimientos(Articulo $articulo): JsonResponse
    {
        $this->authorize('verMovimientos', $articulo);

        $movimientos = $articulo->movimientos()
            ->with(['lote:id,codigo', 'motivoMerma:id,codigo,nombre', 'usuario:id,nombre'])
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        return response()->json([
            'articulos_id' => $articulo->id,
            'saldo' => $this->movimientos->stockDeArticulo($articulo->id),
            'movimientos' => $movimientos->map(fn ($m) => [
                'id' => $m->id,
                'tipo' => $m->tipo->value,
                'sentido' => $m->sentido->value,
                'cantidad' => (float) $m->cantidad,
                'lote' => $m->lote?->codigo,
                'motivo' => $m->motivoMerma?->nombre,
                'referencia' => $m->referencia,
                'usuario' => $m->usuario?->nombre,
                'fecha' => $m->created_at?->toIso8601String(),
            ]),
        ]);
    }

    private function fila(Articulo $articulo): array
    {
        return [
            'id' => $articulo->id,
            'sku' => $articulo->sku,
            'nombre' => $articulo->nombre,
            'descripcion' => $articulo->descripcion,
            'unidad_base' => $articulo->unidadBase?->codigo,
            'precio_venta' => $articulo->precio_venta,
            'tiene_precio' => $articulo->tienePrecio(),
            'stock_minimo' => $articulo->stock_minimo,
            'saldo' => $this->movimientos->stockDeArticulo($articulo->id),
            'se_vende' => $articulo->se_vende,
            'es_insumo' => $articulo->es_insumo,
            'activo' => $articulo->activo,
            'dias_vigencia' => $articulo->vigencia?->dias_vigencia,
            'tiene_regla_vigencia' => $articulo->vigencia !== null,
        ];
    }
}