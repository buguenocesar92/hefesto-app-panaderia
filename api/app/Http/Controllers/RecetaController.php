<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ErrorDePanaderia;
use App\Models\Receta;
use App\Services\Produccion\CalculadoraDeRendimiento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Recetas y su rendimiento.
 *
 * `rendimiento` es la pregunta del dia a dia del hornero: "si hago dos
 * tandas, cuanta harina saco y cuantos panes me quedan". Por eso tiene
 * endpoint propio y no es un calculo buried dentro del detalle.
 */
class RecetaController extends Controller
{
    public function __construct(private readonly CalculadoraDeRendimiento $calculadora) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Receta::class);

        $recetas = Receta::query()
            ->with(['articulo:id,nombre', 'unidadRendimiento:id,codigo', 'ingredientes'])
            ->when($request->boolean('solo_activas', true), fn ($q) => $q->where('activa', true))
            ->orderBy('nombre')
            ->get();

        return response()->json(['recetas' => $recetas->map(fn ($r) => $this->fila($r))]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Receta::class);

        $datos = $request->validate([
            'articulo_id' => ['required', 'uuid', 'exists:articulos,id'],
            'nombre' => ['required', 'string', 'max:160'],
            'version' => ['sometimes', 'integer', 'min:1'],
            'rendimiento_cantidad' => ['required', 'numeric', 'gt:0'],
            'rendimiento_unidad_id' => ['required', 'uuid', 'exists:unidades_medida,id'],
            'notas' => ['nullable', 'string', 'max:2000'],
            'ingredientes' => ['required', 'array', 'min:1'],
            'ingredientes.*.insumo_id' => ['required', 'uuid', 'exists:articulos,id'],
            'ingredientes.*.cantidad' => ['required', 'numeric', 'gt:0'],
            'ingredientes.*.unidad_id' => ['required', 'uuid', 'exists:unidades_medida,id'],
            'ingredientes.*.merma_porcentaje' => ['nullable', 'numeric', 'min:0', 'lt:100'],
        ]);

        $receta = Receta::query()->create([
            'tenant_id' => $request->user()->tenant_id,
            'articulo_id' => $datos['articulo_id'],
            'nombre' => $datos['nombre'],
            'version' => $datos['version'] ?? 1,
            'rendimiento_cantidad' => $datos['rendimiento_cantidad'],
            'rendimiento_unidad_id' => $datos['rendimiento_unidad_id'],
            'notas' => $datos['notas'] ?? null,
            'activa' => true,
        ]);

        foreach ($datos['ingredientes'] as $ingrediente) {
            $receta->ingredientes()->create([
                'tenant_id' => $request->user()->tenant_id,
                'insumo_id' => $ingrediente['insumo_id'],
                'cantidad' => $ingrediente['cantidad'],
                'unidad_id' => $ingrediente['unidad_id'],
                // Por omision sale de `config/panaderia.php`, que es 0: el
                // sistema no inventa una merma que nadie declaro.
                'merma_porcentaje' => $ingrediente['merma_porcentaje']
                    ?? (int) config('panaderia.recetas.merma_por_defecto_porcentaje'),
            ]);
        }

        return response()->json([
            'receta' => $this->fila($receta->fresh(['articulo', 'ingredientes'])),
        ], 201);
    }

    public function show(Receta $receta): JsonResponse
    {
        $this->authorize('view', $receta);

        return response()->json([
            'receta' => $this->fila($receta->load(['articulo', 'unidadRendimiento', 'ingredientes.insumo'])),
        ]);
    }

    public function update(Request $request, Receta $receta): JsonResponse
    {
        $this->authorize('update', $receta);

        $datos = $request->validate([
            'nombre' => ['sometimes', 'string', 'max:160'],
            'rendimiento_cantidad' => ['sometimes', 'numeric', 'gt:0'],
            'rendimiento_unidad_id' => ['sometimes', 'uuid', 'exists:unidades_medida,id'],
            'notas' => ['nullable', 'string', 'max:2000'],
            'activa' => ['sometimes', 'boolean'],
        ]);

        $receta->fill($datos);
        $receta->save();

        return response()->json([
            'receta' => $this->fila($receta->fresh(['articulo', 'ingredientes'])),
        ]);
    }

    /**
     * El calculo de rendimiento.
     *
     * `?tandas=N` dice cuantas corridas se quieren hacer. Por omision, una.
     *
     * Devuelve tambien SI alcanza el stock de cada insumo: es lo que permite
     * avisarle al hornero ANTES de que abra el horno, que es cuando todavia
     * puede hacer algo.
     */
    public function rendimiento(Request $request, Receta $receta): JsonResponse
    {
        $this->authorize('rendimiento', $receta);

        $tandas = (int) $request->query('tandas', 1);

        $calculo = $this->calculadora->paraTandas($receta, $tandas);

        return response()->json([
            'rendimiento' => $calculo,
            'faltantes' => $this->calculadora->faltantes($calculo),
            // Se dice explicitamente que no hay costo: el campo de precio de
            // costo no existe todavia. Ver docs/10.
            'costo' => null,
            'aviso_costo' => 'No se calcula costo de produccion: falta el precio de costo de los insumos.',
        ]);
    }

    private function fila(Receta $receta): array
    {
        return [
            'id' => $receta->id,
            'nombre' => $receta->nombre,
            'version' => $receta->version,
            'articulo' => $receta->relationLoaded('articulo') ? $receta->articulo?->nombre : null,
            'articulo_id' => $receta->articulo_id,
            'rendimiento_cantidad' => (float) $receta->rendimiento_cantidad,
            'rendimiento_unidad' => $receta->relationLoaded('unidadRendimiento')
                ? $receta->unidadRendimiento?->codigo
                : null,
            'activa' => $receta->activa,
            'notas' => $receta->notas,
            'ingredientes' => $receta->relationLoaded('ingredientes')
                ? $receta->ingredientes->map(fn ($i) => [
                    'insumo_id' => $i->insumo_id,
                    'nombre' => $i->relationLoaded('insumo') ? $i->insumo?->nombre : null,
                    'cantidad' => (float) $i->cantidad,
                    'unidad_id' => $i->unidad_id,
                    'merma_porcentaje' => (float) $i->merma_porcentaje,
                    // Lo que hay que sacar del almacen, ya con la merma.
                    'cantidad_total' => $i->cantidadTotal(),
                ])->all()
                : [],
        ];
    }
}