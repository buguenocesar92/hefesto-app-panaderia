<?php

declare(strict_types=1);

namespace Tests;

use App\Models\Articulo;
use App\Models\MotivoMerma;
use App\Models\Tenant;
use App\Models\Ubicacion;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use App\Models\Vigencia;
use App\Services\Inventario\RegistroDeMovimiento;
use App\Services\Tenancy\ContextoTenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

/**
 * Base de las pruebas de la panaderia.
 *
 * Aporta lo que mas se prueba:
 *
 *  - `comoUsuario()`, que autentica a una persona Y fija su contexto de
 *    panaderia en la base. IMPORTANTE: `actingAs()` por si solo NO fija el
 *    contexto, porque el middleware `ResolverTenant` corre dentro del grupo
 *    `web`, que estas pruebas no ejecutan. Sin fijar el contexto, la RLS no
 *    tendria contra que filtrar y las pruebas de aislamiento no probarian
 *    nada.
 *
 *  - `crearPanaderia()`, que arma una panaderia con personas, unidades,
 *    ubicaciones, motivos y reglas listas para operar.
 *
 *  - `cargarStock()`, que carga mercaderia POR MOVIMIENTOS, porque no hay
 *    otra forma: el sistema no permite escribir saldos.
 */
abstract class TestCase extends BaseTestCase
{
    /** Panaderia fijada para la prueba en curso. */
    protected ?string $panaderiaDePrueba = null;

    /** @var array<string, RegistroDeMovimiento> */
    private array $registros = [];

    /**
     * Autentica a una persona y fija su contexto de panaderia en la base.
     */
    protected function comoUsuario(Usuario $usuario): static
    {
        $this->actingAs($usuario);

        return $this->fijarPanaderia($usuario->tenant_id);
    }

    /**
     * Emula lo que hace `App\Http\Middleware\ResolverTenant`.
     *
     * Se usa `set_config(..., false)`, o sea con alcance de SESION y no de
     * transaccion. En la aplicacion real es al reves (alcance de
     * transaccion, para que el worker de colas no arrastre la panaderia de
     * otra peticion). En las pruebas cada caso abre su propia aplicacion y su
     * propia conexion, asi que el valor no sobrevive entre pruebas.
     */
    protected function fijarPanaderia(?string $panaderiaId): static
    {
        if ($panaderiaId === null) {
            return $this;
        }

        DB::select('SELECT set_config(?, ?, false)', [ContextoTenant::VARIABLE, $panaderiaId]);

        $this->panaderiaDePrueba = $panaderiaId;

        return $this;
    }

    /**
     * Crea una panaderia con todo lo minimo para operar.
     *
     * @return array{
     *     panaderia: Tenant,
     *     administrador: Usuario,
     *     encargado: Usuario,
     *     hornero: Usuario,
     *     mostrador: Usuario,
     *     unidad: UnidadMedida,
     *     unidadBase: UnidadMedida,
     *     bodega: Ubicacion,
     *     mostradorUbicacion: Ubicacion,
     *     insumo: Articulo,
     *     producto: Articulo,
     *     motivoVencido: MotivoMerma,
     *     motivoQuemado: MotivoMerma
     * }
     */
    protected function crearPanaderia(string $slug = 'ejemplo.cl'): array
    {
        $panaderia = Tenant::factory()->create([
            'slug' => $slug,
            'nombre' => 'Panaderia ' . $slug,
        ]);

        $this->fijarPanaderia($panaderia->id);

        $administrador = Usuario::factory()->administrador()->de($panaderia)->create([
            'email' => 'admin@' . $slug,
        ]);

        $encargado = Usuario::factory()->encargado()->de($panaderia)->create([
            'email' => 'encargado@' . $slug,
        ]);

        $hornero = Usuario::factory()->horno()->de($panaderia)->create([
            'email' => 'horno@' . $slug,
        ]);

        $vendedor = Usuario::factory()->mostrador()->de($panaderia)->create([
            'email' => 'mostrador@' . $slug,
        ]);

        $unidad = UnidadMedida::factory()->de($panaderia)->base()->create([
            'codigo' => 'UN',
            'nombre' => 'Unidad',
        ]);

        // El kilogramo: la unidad en la que se maneja de verdad la harina.
        $unidadBase = UnidadMedida::factory()->de($panaderia)->factorDe(1000)->create([
            'codigo' => 'G',
            'nombre' => 'Gramo',
        ]);

        $bodega = Ubicacion::factory()->de($panaderia)->bodega()->create([
            'codigo' => 'BOD',
            'nombre' => 'Bodega',
        ]);

        $mostradorUbicacion = Ubicacion::factory()->de($panaderia)->mostrador()->create([
            'codigo' => 'MOS',
            'nombre' => 'Mostrador',
        ]);

        // El insumo: harina. NO se vende en el mostrador y SI se consume en
        // receta.
        $insumo = Articulo::factory()->insumo()->conUnidades($panaderia, $unidadBase)->create([
            'sku' => 'INS-HARINA',
            'nombre' => 'Harina',
            'stock_minimo' => 5,
        ]);

        // El producto: pan. Se vende en el mostrador y NO se fabrica con el.
        $producto = Articulo::factory()->productoDeVenta()->conPrecio(1500)
            ->conUnidades($panaderia, $unidad)
            ->create([
                'sku' => 'PAN-001',
                'nombre' => 'Pan',
            ]);

        $motivoVencido = MotivoMerma::factory()->de($panaderia)->vencido()->create();
        $motivoQuemado = MotivoMerma::factory()->de($panaderia)->create([
            'codigo' => 'quemado',
            'nombre' => 'Quemado en el horno',
        ]);

        return [
            'panaderia' => $panaderia,
            'administrador' => $administrador,
            'encargado' => $encargado,
            'hornero' => $hornero,
            'mostrador' => $vendedor,
            'unidad' => $unidad,
            'unidadBase' => $unidadBase,
            'bodega' => $bodega,
            'mostradorUbicacion' => $mostradorUbicacion,
            'insumo' => $insumo,
            'producto' => $producto,
            'motivoVencido' => $motivoVencido,
            'motivoQuemado' => $motivoQuemado,
        ];
    }

    /**
     * El registro de movimientos, ya resuelto una vez.
     */
    protected function registro(): RegistroDeMovimiento
    {
        if (! isset($this->registros['panaderia'])) {
            $this->registros['panaderia'] = app(RegistroDeMovimiento::class);
        }

        return $this->registros['panaderia'];
    }

    /**
     * Carga stock POR MOVIMIENTOS, que es la unica via.
     *
     * Para un insumo a granel basta `conLote = false`: su saldo vive en el
     * libro de movimientos y no en ningun lote.
     *
     * @return array{lote: \App\Models\LoteProducto|null, saldo: float}
     */
    protected function cargarStock(
        Articulo $articulo,
        Ubicacion $ubicacion,
        float $cantidad,
        bool $conLote = true,
        ?\Illuminate\Support\Carbon $fechaVencimiento = null,
    ): array {
        if ($conLote === false) {
            $this->registro()->entrar([
                'articulo_id' => $articulo->id,
                'cantidad' => $cantidad,
                'ubicacion_id' => $ubicacion->id,
                'referencia' => 'PRUEBA',
            ]);

            return [
                'lote' => null,
                'saldo' => $this->registro()->stockDeArticulo($articulo->id),
            ];
        }

        $lote = \App\Models\LoteProducto::query()->create([
            'tenant_id' => $articulo->tenant_id,
            'articulo_id' => $articulo->id,
            'ubicacion_id' => $ubicacion->id,
            'tanda_id' => null,
            'codigo' => 'LOTE-PRUEBA-' . strtoupper(bin2hex(random_bytes(3))),
            'cantidad_inicial' => $cantidad,
            'cantidad_disponible' => $cantidad,
            'fecha_produccion' => now()->toDateString(),
            'fecha_vencimiento' => $fechaVencimiento?->toDateString(),
            'estado' => \App\Enums\EstadoLote::DISPONIBLE,
        ]);

        $this->registro()->entrar([
            'articulo_id' => $articulo->id,
            'cantidad' => $cantidad,
            'ubicacion_id' => $ubicacion->id,
            'referencia' => 'PRUEBA',
        ], $lote);

        return [
            'lote' => $lote->fresh(),
            'saldo' => $this->registro()->stockDeArticulo($articulo->id),
        ];
    }

    /**
     * Fija la regla de vencimiento de un producto.
     *
     * `null` significa "este producto no caduca", que es una decision valida
     * y distinta de "caduca hoy".
     */
    protected function fijarVigencia(
        Articulo $producto,
        ?int $dias,
        bool $mermaAutomatica = false
    ): Vigencia {
        return Vigencia::query()->create([
            'tenant_id' => $producto->tenant_id,
            'articulo_id' => $producto->id,
            'dias_vigencia' => $dias,
            'merma_automatica' => $mermaAutomatica,
        ]);
    }

    /**
     * Lanza la excepcion de base que se espera, sin repetir el try/catch.
     */
    protected function esperarFalloDeBase(callable $operacion): void
    {
        try {
            $operacion();
        } catch (QueryException) {
            return;
        }

        $this->fail('Se esperaba un fallo de PostgreSQL y la operacion paso sin problema.');
    }
}