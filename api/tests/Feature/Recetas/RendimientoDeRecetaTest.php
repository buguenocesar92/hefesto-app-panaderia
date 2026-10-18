<?php

declare(strict_types=1);

namespace Tests\Feature\Recetas;

use App\Models\Receta;
use App\Models\RecetaIngrediente;
use App\Services\Produccion\CalculadoraDeRendimiento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El calculo de rendimiento de receta.
 *
 * LA CUENTA QUE MAS SE CONFUNDE
 * -----------------------------
 * "Lo que SALE" y "lo que ENTRA" son dos numeros distintos:
 *
 *  - El rendimiento dice lo que sale del horno: 40 panes por tanda.
 *  - El consumo dice lo que hay que SACAR del almacen: 21 kilos de harina,
 *    no 20, porque hay una merma estimada del 5 %.
 *
 * Si se usara el mismo numero para los dos, al final de la semana faltaria
 * harina para el ultimo pan y no se sabria por que.
 */
class RendimientoDeRecetaTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_rendimiento_multiplica_por_el_numero_de_tandas(): void
    {
        $panaderia = $this->crearPanaderia();
        $receta = $this->crearReceta($panaderia, rinde: 40.0);

        $calculo = app(CalculadoraDeRendimiento::class)->paraTandas($receta, 3);

        $this->assertSame(120.0, $calculo['rendimiento_total']);
        $this->assertSame(40.0, $calculo['rendimiento_tanda']);
        $this->assertSame(3, $calculo['numero_tandas']);
    }

    public function test_el_consumo_de_insumos_incluye_la_merma_estimada(): void
    {
        $panaderia = $this->crearPanaderia();
        $receta = $this->crearReceta($panaderia, rinde: 40.0);

        // 20 000 gramos con 5 % de merma: hay que sacar 21 000.
        $receta->ingredientes()->update([
            'cantidad' => 20000,
            'merma_porcentaje' => 5,
        ]);

        $calculo = app(CalculadoraDeRendimiento::class)->paraTandas($receta->fresh(), 1);

        $this->assertSame(21000.0, $calculo['insumos'][0]['cantidad_total']);
        $this->assertSame(
            5.0,
            $calculo['insumos'][0]['merma_porcentaje'],
            'La merma estimada se informa: es un dato declarado, no un secreto.'
        );
    }

    public function test_el_consumo_de_insumos_multiplica_por_el_numero_de_tandas(): void
    {
        $panaderia = $this->crearPanaderia();
        $receta = $this->crearReceta($panaderia, rinde: 40.0);

        $calculo = app(CalculadoraDeRendimiento::class)->paraTandas($receta, 2);

        // 10 000 gramos por tanda, dos tandas, sin merma estimada.
        $this->assertSame(20000.0, $calculo['insumos'][0]['cantidad_total']);
        $this->assertSame(80.0, $calculo['rendimiento_total']);
    }

    public function test_avisa_cuando_no_alcanza_el_stock(): void
    {
        $panaderia = $this->crearPanaderia();
        $receta = $this->crearReceta($panaderia, rinde: 40.0);

        // Se carga solo 5 kilos y la receta pide 10 por tanda.
        $this->cargarStock($panaderia['insumo'], $panaderia['bodega'], 5000, false);

        $calculo = app(CalculadoraDeRendimiento::class)->paraTandas($receta, 1);

        $this->assertFalse($calculo['alcanza']);

        $faltantes = app(CalculadoraDeRendimiento::class)->faltantes($calculo);

        $this->assertCount(1, $faltantes);
        $this->assertSame('Harina', $faltantes[0]['nombre']);
        $this->assertSame(5000.0, $faltantes[0]['falta']);
    }

    public function test_alcanza_si_hay_suficiente_stock(): void
    {
        $panaderia = $this->crearPanaderia();
        $receta = $this->crearReceta($panaderia, rinde: 40.0);

        $this->cargarStock($panaderia['insumo'], $panaderia['bodega'], 30000, false);

        $calculo = app(CalculadoraDeRendimiento::class)->paraTandas($receta, 2);

        $this->assertTrue($calculo['alcanza']);
        $this->assertSame([], app(CalculadoraDeRendimiento::class)->faltantes($calculo));
    }

    /**
     * Una receta sin insumos descontaria cero y crearia lotes de pan hecho
     * con nada. Tiene que fallar antes.
     */
    public function test_una_receta_sin_ingredientes_no_produce_nada(): void
    {
        $panaderia = $this->crearPanaderia();
        $receta = $this->crearReceta($panaderia, rinde: 40.0);

        $calculo = app(CalculadoraDeRendimiento::class)->paraTandas($receta, 1);

        $this->assertSame([], $calculo['insumos']);
        $this->assertSame(0.0, $calculo['rendimiento_tanda']);

        // El servicio que arma la tanda es el que corta: una receta vacia
        // nunca llega a crear un lote.
        $this->expectException(\App\Exceptions\ErrorDePanaderia::class);
        $this->expectExceptionMessage('no tiene ingredientes cargados');

        app(\App\Services\Produccion\RegistradorDeTanda::class)->registrar(
            $this->crearTanda($panaderia, $receta),
            [
                'cantidad_producida' => 40,
                'ubicacion_id' => $panaderia['mostradorUbicacion']->id,
            ]
        );
    }

    public function test_no_se_peden_cero_tandas(): void
    {
        $panaderia = $this->crearPanaderia();
        $receta = $this->crearReceta($panaderia, rinde: 40.0);

        $this->expectException(\App\Exceptions\ErrorDePanaderia::class);

        app(CalculadoraDeRendimiento::class)->paraTandas($receta, 0);
    }

    /**
     * Registrar una tanda descuenta los insumos y crea el lote con la
     * cantidad que salio del horno.
     */
    public function test_registrar_una_tanda_descuenta_insumos_y_crea_el_lote(): void
    {
        $panaderia = $this->crearPanaderia();
        $receta = $this->crearReceta($panaderia, rinde: 40.0);

        $this->fijarVigencia($panaderia['producto'], 1);

        $this->cargarStock($panaderia['insumo'], $panaderia['bodega'], 15000, false);

        $tanda = $this->crearTanda($panaderia, $receta);

        $resultado = app(\App\Services\Produccion\RegistradorDeTanda::class)->registrar($tanda, [
            'cantidad_producida' => 38,
            'ubicacion_id' => $panaderia['mostradorUbicacion']->id,
        ]);

        // Salen 38, no 40: se registro lo que realmente salio del horno.
        $this->assertSame(38.0, (float) $resultado['lote']->cantidad_inicial);
        $this->assertSame(38.0, (float) $resultado['tanda']->cantidad_producida);

        // La harina bajo de 15 000 a 5 000: 10 000 se consumieron.
        $this->assertSame(
            5000.0,
            $this->registro()->stockDeArticulo($panaderia['insumo']->id)
        );

        // Y el pan aparece en el inventario.
        $this->assertSame(
            38.0,
            $this->registro()->stockDeArticulo($panaderia['producto']->id)
        );
    }

    public function test_una_tanda_sin_insumos_no_toca_nada(): void
    {
        $panaderia = $this->crearPanaderia();
        $receta = $this->crearReceta($panaderia, rinde: 40.0);

        // No hay harina en el almacen.
        $tanda = $this->crearTanda($panaderia, $receta);

        try {
            app(\App\Services\Produccion\RegistradorDeTanda::class)->registrar($tanda, [
                'cantidad_producida' => 40,
                'ubicacion_id' => $panaderia['mostradorUbicacion']->id,
            ]);
        } catch (\App\Exceptions\ErrorDePanaderia) {
            // Se espera el fallo.
        }

        // Lo importante: no se desconto nada y no se creo ningun lote. Si
        // descontara la mitad y fallara despues, el almacen quedaria con la
        // receta a medias.
        $this->assertSame(0.0, $this->registro()->stockDeArticulo($panaderia['insumo']->id));
        $this->assertSame(
            0,
            \App\Models\LoteProducto::query()->count(),
            'No deberia quedar ningun lote de una tanda que no se pudo hacer.'
        );
    }

    /**
     * @param  array<string, mixed>  $panaderia
     */
    private function crearReceta(array $panaderia, float $rinde): Receta
    {
        $receta = Receta::query()->create([
            'tenant_id' => $panaderia['panaderia']->id,
            'articulo_id' => $panaderia['producto']->id,
            'nombre' => 'Marraqueta',
            'version' => 1,
            'rendimiento_cantidad' => $rinde,
            'rendimiento_unidad_id' => $panaderia['unidad']->id,
            'activa' => true,
        ]);

        RecetaIngrediente::query()->create([
            'tenant_id' => $panaderia['panaderia']->id,
            'receta_id' => $receta->id,
            'insumo_id' => $panaderia['insumo']->id,
            'cantidad' => 10000,
            'unidad_id' => $panaderia['unidadBase']->id,
            // 0 por omision: sin merma declarada no hay merma.
            'merma_porcentaje' => 0,
        ]);

        return $receta;
    }

    /**
     * @param  array<string, mixed>  $panaderia
     */
    private function crearTanda(array $panaderia, Receta $receta): \App\Models\Tanda
    {
        return \App\Models\Tanda::query()->create([
            'tenant_id' => $panaderia['panaderia']->id,
            'receta_id' => $receta->id,
            'numero' => 'TA-PRUEBA-0001',
            'fecha' => now()->toDateString(),
            'cantidad_planificada' => 40,
            'estado' => \App\Enums\EstadoTanda::PLANIFICADA,
            'hornero_id' => $panaderia['hornero']->id,
        ]);
    }
}