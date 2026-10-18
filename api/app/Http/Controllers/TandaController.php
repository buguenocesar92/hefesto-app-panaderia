<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EstadoTanda;
use App\Http\Requests\RegistrarTandaRequest;
use App\Models\Tanda;
use App\Models\Ubicacion;
use App\Services\Produccion\CerradorDeSobrante;
use App\Services\Produccion\RegistradorDeTanda;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Produccion por tanda.
 *
 * `registrar` es la accion central: descuenta insumos y crea el lote.
 * `sobrante` es la que cierra el dia: ve lo que quedo de las corridas
 * anteriores y o lo pasa a la bodega o lo merma. Sin las dos, la merma se
 * esconde en un cajon.
 */
class TandaController extends Controller
{
    public function __construct(
        private readonly RegistradorDeTanda $registrador,
        private readonly CerradorDeSobrante $cerrador,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Tanda::class);

        $tandas = Tanda::query()
            ->with(['receta:id,nombre', 'hornero:id,nombre', 'lotes'])
            ->when($request->query('fecha'), fn ($q, $f) => $q->whereDate('fecha', $f))
            ->when($request->query('estado'), fn ($q, $e) => $q->where('estado', $e))
            ->orderByDesc('fecha')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return response()->json(['tandas' => $tandas->map(fn ($t) => $this->fila($t))]);
    }

    /** Las que se pueden arrancar hoy: lo que falta por producir. */
    public function planificadas(): JsonResponse
    {
        $this->authorize('viewAny', Tanda::class);

        $tandas = Tanda::query()
            ->with(['receta:id,nombre,rendimiento_cantidad', 'hornero:id,nombre'])
            ->whereIn('estado', [EstadoTanda::PLANIFICADA->value, EstadoTanda::EN_CURSO->value])
            ->orderBy('fecha')
            ->get();

        return response()->json(['tandas' => $tandas->map(fn ($t) => $this->fila($t))]);
    }

    /** Agenda una tanda nueva. */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Tanda::class);

        $datos = $request->validate([
            'receta_id' => ['required', 'uuid', 'exists:recetas,id'],
            'fecha' => ['required', 'date'],
            'cantidad_planificada' => ['nullable', 'numeric', 'gt:0'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ]);

        $usuario = $request->user();

        $tanda = Tanda::query()->create([
            'tenant_id' => $usuario->tenant_id,
            'receta_id' => $datos['receta_id'],
            'numero' => $this->numeroDeTanda($usuario->tenant_id, $datos['fecha']),
            'fecha' => Carbon::parse($datos['fecha'])->toDateString(),
            'cantidad_planificada' => $datos['cantidad_planificada'] ?? null,
            'estado' => EstadoTanda::PLANIFICADA,
            'hornero_id' => $usuario->id,
            'notas' => $datos['notas'] ?? null,
        ]);

        return response()->json(['tanda' => $this->fila($tanda->fresh(['receta', 'hornero']))], 201);
    }

    public function show(Tanda $tanda): JsonResponse
    {
        $this->authorize('view', $tanda);

        return response()->json([
            'tanda' => $this->fila($tanda->load(['receta', 'hornero', 'lotes.articulo', 'lotes.ubicacion'])),
        ]);
    }

    /**
     * Registra que salio del horno: descuenta insumos y crea el lote.
     *
     * Todo pasa en una transaccion (ver `RegistradorDeTanda`). Si falta
     * insumos, no se toca nada: se avisa que falta que y en que cantidad.
     */
    public function registrar(RegistrarTandaRequest $request, Tanda $tanda): JsonResponse
    {
        $this->authorize('registrar', $tanda);

        $resultado = $this->registrador->registrar($tanda, $request->datos());

        return response()->json([
            'tanda' => $this->fila($resultado['tanda']->load(['receta', 'hornero'])),
            'lote' => [
                'id' => $resultado['lote']->id,
                'codigo' => $resultado['lote']->codigo,
                'cantidad_inicial' => (float) $resultado['lote']->cantidad_inicial,
                'fecha_vencimiento' => $resultado['lote']->fecha_vencimiento?->toDateString(),
                // Si el producto no tiene regla configurada, esto viene en
                // `null` y la interfaz lo tiene que mostrar. No es un error:
                // es la forma de que la panaderia se entere de que le falta
                // configurar el vencimiento de ese producto.
                'tiene_regla_vigencia' => $resultado['lote']->tieneVigencia(),
            ],
            'rendimiento' => $resultado['rendimiento'],
        ], 201);
    }

    /**
     * El cierre de turno: que se hace con lo que quedo.
     *
     * Un lote vencido se merma con su motivo. Uno que todavia sirve pasa a la
     * bodega y aparece en el inventario del dia siguiente.
     */
    public function sobrante(Request $request, Tanda $tanda): JsonResponse
    {
        $this->authorize('sobrante', $tanda);

        $datos = $request->validate([
            'fecha' => ['sometimes', 'date'],
            'bodega_id' => ['nullable', 'uuid', 'exists:ubicaciones,id'],
        ]);

        $bodega = isset($datos['bodega_id'])
            ? Ubicacion::query()->findOrFail($datos['bodega_id'])
            : null;

        $resultado = $this->cerrador->cerrarDia(
            $tanda,
            isset($datos['fecha']) ? Carbon::parse($datos['fecha']) : Carbon::parse($tanda->fecha),
            $bodega
        );

        return response()->json($resultado);
    }

    public function anular(Request $request, Tanda $tanda): JsonResponse
    {
        $this->authorize('anular', $tanda);

        if ($tanda->estado === EstadoTanda::TERMINADA) {
            // Una tanda terminada YA movio inventario: descuento insumos y
            // creo lotes. Anularla aca dejaria stock que no corresponde.
            // Lo que se hace con esa mercaderia es merma, con su motivo.
            return response()->json([
                'mensaje' => 'Esta tanda ya produjo mercaderia. Registra la merma del lote en vez de anular la tanda.',
                'codigo' => 'tanda_ya_terminada',
            ], 422);
        }

        $tanda->estado = EstadoTanda::ANULADA;
        $tanda->cerrada_en = now();
        $tanda->save();

        return response()->json(['tanda' => $this->fila($tanda->fresh(['receta', 'hornero']))]);
    }

    private function fila(Tanda $tanda): array
    {
        return [
            'id' => $tanda->id,
            'numero' => $tanda->numero,
            'receta' => $tanda->receta?->nombre,
            'receta_id' => $tanda->receta_id,
            'fecha' => $tanda->fecha?->toDateString(),
            'cantidad_planificada' => $tanda->cantidad_planificada,
            'cantidad_producida' => $tanda->cantidad_producida,
            'estado' => $tanda->estado->value,
            'hornero' => $tanda->hornero?->nombre,
            'lotes' => $tanda->relationLoaded('lotes')
                ? $tanda->lotes->map(fn ($l) => [
                    'codigo' => $l->codigo,
                    'cantidad_disponible' => (float) $l->cantidad_disponible,
                    'estado' => $l->estado->value,
                ])->all()
                : [],
            'notas' => $tanda->notas,
        ];
    }

    private function numeroDeTanda(string $tenantId, string $fecha): string
    {
        $prefijo = 'TA-' . Carbon::parse($fecha)->format('Ymd') . '-';

        $ultimo = Tanda::query()
            ->where('tenant_id', $tenantId)
            ->where('numero', 'like', $prefijo . '%')
            ->orderByDesc('numero')
            ->value('numero');

        $consecutivo = $ultimo === null ? 1 : ((int) substr($ultimo, strlen($prefijo))) + 1;

        return $prefijo . str_pad((string) $consecutivo, 4, '0', STR_PAD_LEFT);
    }
}