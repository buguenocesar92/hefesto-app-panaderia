<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\RolOperador;
use App\Enums\TipoUbicacion;
use App\Models\Articulo;
use App\Models\MotivoMerma;
use App\Models\Ubicacion;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use App\Models\Vigencia;
use App\Policies\ConfiguracionPolicy;
use App\Services\Caducidad\CalculadoraDeVencimiento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Configuracion: las reglas que la panaderia ajusta.
 *
 * Aqui viven tres cosas que no se inventan y no se calculan:
 *
 *  - Las VIGENCIAS de cada producto. El pan caduca hoy, el sándwich mañana:
 *    es una regla por producto y la decide la panaderia.
 *  - Los MOTIVOS de merma, que son los que hacen que el informe signifique
 *    algo.
 *  - Las PERSONAS y su rol.
 *
 * Todo es del `administrador`, y el rol de nadie se cambia a si mismo: sin
 * ese control, el unico administrador podria degradarse y dejar el sistema
 * sin nadie que lo administre.
 */
class ConfiguracionController extends Controller
{
    public function __construct(private readonly CalculadoraDeVencimiento $vencimientos) {}

    /** El resumen: que falta configurar. */
    public function resumen(): JsonResponse
    {
        $this->authorize('ver', ConfiguracionPolicy::class);

        $articulos = Articulo::query()->where('activo', true)->pluck('id');

        $sinVigencia = Articulo::query()
            ->where('activo', true)
            ->whereDoesntHave('vigencia')
            ->count();

        return response()->json([
            'articulos_activos' => $articulos->count(),
            'articulos_sin_regla_vigencia' => $sinVigencia,
            'motivos_merma_activos' => MotivoMerma::query()->where('activo', true)->count(),
            'unidades' => UnidadMedida::query()->count(),
            'ubicaciones' => Ubicacion::query()->where('activo', true)->count(),
            // Esto se muestra a proposito: que falten reglas se ve, no se
            // esconde con un valor por omision.
            'vigencia_por_defecto_dias' => config('panaderia.caducidad.dias_por_defecto'),
            'dias_aviso_caducidad' => (int) config('panaderia.caducidad.dias_aviso'),
            'minutos_maximos_venta' => (int) config('panaderia.mostrador.minutos_maximos'),
        ]);
    }

    // --- Vigencias -------------------------------------------------------

    public function vigencias(Request $request): JsonResponse
    {
        $this->authorize('ver', ConfiguracionPolicy::class);

        $articulos = Articulo::query()
            ->where('activo', true)
            ->with('vigencia')
            ->orderBy('nombre')
            ->get();

        return response()->json([
            'articulos' => $articulos->map(fn ($a) => [
                'articulo_id' => $a->id,
                'sku' => $a->sku,
                'nombre' => $a->nombre,
                // NULL = el producto NO tiene regla. No es cero: cero seria
                // "caduca hoy", que es una regla distinta.
                'dias_vigencia' => $a->vigencia?->dias_vigencia,
                'merma_automatica' => (bool) ($a->vigencia?->merma_automatica ?? false),
                'tiene_regla' => $a->vigencia !== null,
                // Lo que se va a aplicar de verdad, contando el valor por
                // omision de la configuracion. Si da `null`, el lote saldra
                // sin fecha de vencimiento.
                'regla_efectiva' => $this->vencimientos->reglaDe($a),
                'notas' => $a->vigencia?->notas,
            ]),
        ]);
    }

    /**
     * Guarda la regla de vencimiento de UN producto.
     *
     * `dias_vigencia` admite NULL a proposito: es la forma de decir "este
     * producto no caduca". Por eso la regla es `nullable` y no `required`.
     */
    public function guardarVigencia(Request $request): JsonResponse
    {
        $this->authorize('guardarVigencia', ConfiguracionPolicy::class);

        $datos = $request->validate([
            'articulo_id' => ['required', 'uuid', 'exists:articulos,id'],
            'dias_vigencia' => ['present', 'nullable', 'integer', 'min:0', 'max:3650'],
            'merma_automatica' => ['sometimes', 'boolean'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ]);

        $vigencia = Vigencia::query()->updateOrCreate(
            [
                'tenant_id' => $request->user()->tenant_id,
                'articulo_id' => $datos['articulo_id'],
            ],
            [
                'dias_vigencia' => $datos['dias_vigencia'] ?? null,
                'merma_automatica' => $datos['merma_automatica'] ?? false,
                'notas' => $datos['notas'] ?? null,
            ]
        );

        return response()->json(['vigencia' => [
            'articulo_id' => $vigencia->articulo_id,
            'dias_vigencia' => $vigencia->dias_vigencia,
            'merma_automatica' => $vigencia->merma_automatica,
        ]], 201);
    }

    // --- Motivos de merma ------------------------------------------------

    public function motivosMerma(): JsonResponse
    {
        $this->authorize('ver', ConfiguracionPolicy::class);

        return response()->json([
            'motivos' => MotivoMerma::query()->orderBy('nombre')->get()->map(fn ($m) => [
                'id' => $m->id,
                'codigo' => $m->codigo,
                'nombre' => $m->nombre,
                'descripcion' => $m->descripcion,
                'merma_por_vencimiento' => $m->merma_por_vencimiento,
                'activo' => $m->activo,
            ]),
        ]);
    }

    public function guardarMotivoMerma(Request $request): JsonResponse
    {
        $this->authorize('guardarMotivoMerma', ConfiguracionPolicy::class);

        $datos = $request->validate([
            'codigo' => ['required', 'string', 'max:32'],
            'nombre' => ['required', 'string', 'max:120'],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'merma_por_vencimiento' => ['sometimes', 'boolean'],
            'activo' => ['sometimes', 'boolean'],
        ]);

        $motivo = MotivoMerma::query()->updateOrCreate(
            [
                'tenant_id' => $request->user()->tenant_id,
                'codigo' => $datos['codigo'],
            ],
            [
                'nombre' => $datos['nombre'],
                'descripcion' => $datos['descripcion'] ?? null,
                'merma_por_vencimiento' => $datos['merma_por_vencimiento'] ?? false,
                'activo' => $datos['activo'] ?? true,
            ]
        );

        return response()->json(['motivo_id' => $motivo->id], 201);
    }

    // --- Unidades --------------------------------------------------------

    public function unidades(): JsonResponse
    {
        $this->authorize('ver', ConfiguracionPolicy::class);

        return response()->json([
            'unidades' => UnidadMedida::query()->orderBy('codigo')->get()->map(fn ($u) => [
                'id' => $u->id,
                'codigo' => $u->codigo,
                'nombre' => $u->nombre,
                'unidades_por_base' => (float) $u->unidades_por_base,
            ]),
        ]);
    }

    public function guardarUnidad(Request $request): JsonResponse
    {
        $this->authorize('guardarUnidad', ConfiguracionPolicy::class);

        $datos = $request->validate([
            'codigo' => ['required', 'string', 'max:24'],
            'nombre' => ['required', 'string', 'max:80'],
            // El factor tiene que ser mayor que cero: una unidad que vale
            // cero rompe toda conversion de cantidad.
            'unidades_por_base' => ['required', 'numeric', 'gt:0'],
        ]);

        $unidad = UnidadMedida::query()->updateOrCreate(
            [
                'tenant_id' => $request->user()->tenant_id,
                'codigo' => $datos['codigo'],
            ],
            [
                'nombre' => $datos['nombre'],
                'unidades_por_base' => $datos['unidades_por_base'],
            ]
        );

        return response()->json(['unidad_id' => $unidad->id], 201);
    }

    // --- Ubicaciones -----------------------------------------------------

    public function ubicaciones(): JsonResponse
    {
        $this->authorize('ver', ConfiguracionPolicy::class);

        return response()->json([
            'ubicaciones' => Ubicacion::query()->orderBy('codigo')->get()->map(fn ($u) => [
                'id' => $u->id,
                'codigo' => $u->codigo,
                'nombre' => $u->nombre,
                'tipo' => $u->tipo->value,
                'activo' => $u->activo,
            ]),
            'tipos' => array_map(fn (TipoUbicacion $t) => $t->value, TipoUbicacion::cases()),
        ]);
    }

    public function guardarUbicacion(Request $request): JsonResponse
    {
        $this->authorize('guardarUbicacion', ConfiguracionPolicy::class);

        $datos = $request->validate([
            'codigo' => ['required', 'string', 'max:32'],
            'nombre' => ['required', 'string', 'max:120'],
            'tipo' => ['required', 'string', 'in:' . implode(',', array_map(
                fn (TipoUbicacion $t) => $t->value,
                TipoUbicacion::cases()
            ))],
            'activo' => ['sometimes', 'boolean'],
        ]);

        $ubicacion = Ubicacion::query()->updateOrCreate(
            [
                'tenant_id' => $request->user()->tenant_id,
                'codigo' => $datos['codigo'],
            ],
            [
                'nombre' => $datos['nombre'],
                'tipo' => $datos['tipo'],
                'activo' => $datos['activo'] ?? true,
            ]
        );

        return response()->json(['ubicacion_id' => $ubicacion->id], 201);
    }

    // --- Personas --------------------------------------------------------

    public function personas(): JsonResponse
    {
        $this->authorize('verPersonas', ConfiguracionPolicy::class);

        return response()->json([
            'personas' => Usuario::query()->orderBy('nombre')->get()->map(fn ($u) => [
                'id' => $u->id,
                'email' => $u->email,
                'nombre' => $u->nombre,
                'rol' => $u->rol->value,
                'activo' => $u->activo,
            ]),
            'roles' => array_map(fn (RolOperador $r) => $r->value, RolOperador::cases()),
        ]);
    }

    /**
     * Cambia el rol de una persona.
     *
     * La Policy impide que alguien se cambie el rol a si mismo. Sin eso, el
     * unico administrador de la panaderia podria degradarse y dejar el
     * sistema sin nadie que lo administre, sin que nadie lo decidiera.
     */
    public function cambiarRol(Request $request, Usuario $usuario): JsonResponse
    {
        $this->authorize('cambiarRol', [ConfiguracionPolicy::class, $usuario]);

        $datos = $request->validate([
            'rol' => ['required', 'string', 'in:' . implode(',', array_map(
                fn (RolOperador $r) => $r->value,
                RolOperador::cases()
            ))],
            'activo' => ['sometimes', 'boolean'],
        ]);

        $usuario->rol = RolOperador::from($datos['rol']);
        $usuario->activo = $datos['activo'] ?? $usuario->activo;
        $usuario->save();

        return response()->json([
            'persona' => [
                'id' => $usuario->id,
                'rol' => $usuario->rol->value,
                'activo' => $usuario->activo,
            ],
        ]);
    }
}