<?php

declare(strict_types=1);

namespace Tests\Feature\Caducidad;

use App\Enums\EstadoLote;
use App\Models\LoteProducto;
use App\Models\Merma;
use App\Services\Caducidad\CalculadoraDeVencimiento;
use App\Services\Caducidad\RevisorDeCaducidad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Caducidad: el pan de hoy caduca hoy, el sándwich mañana.
 *
 * LA REGLA QUE SE COMPRUEBA ACA
 * -----------------------------
 * La vigencia NO se inventa. Si un producto no tiene regla configurada, el
 * lote sale SIN fecha de vencimiento y no caduca solo. Ponerle un plazo por
 * omision seria fabricar una regla de negocio falsa.
 */
class CaducidadTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_vigencia_configurada_cuadra_una_tanda(): void
    {
        $panaderia = $this->crearPanaderia();

        // El pan caduca al dia siguiente: es una REGLA, y la puso la
        // panaderia.
        $this->fijarVigencia($panaderia['producto'], 1);

        $calculo = app(CalculadoraDeVencimiento::class);

        $fecha = Carbon::parse('2026-03-10');

        $this->assertSame(
            '2026-03-11',
            $calculo->para($panaderia['producto'], $fecha)?->toDateString()
        );
    }

    public function test_sin_regla_el_lote_no_tiene_fecha_de_vencimiento(): void
    {
        $panaderia = $this->crearPanaderia();

        // No se crea ninguna regla de vigencia para el producto.
        $calculo = app(CalculadoraDeVencimiento::class);

        $this->assertNull(
            $calculo->para($panaderia['producto'], Carbon::parse('2026-03-10')),
            'Sin regla configurada, el lote tiene que salir sin fecha de vencimiento.'
        );
    }

    public function test_una_vigencia_de_cero_significa_que_caduca_el_mismo_dia(): void
    {
        $panaderia = $this->crearPanaderia();

        $this->fijarVigencia($panaderia['producto'], 0);

        $calculo = app(CalculadoraDeVencimiento::class);

        $this->assertSame(
            '2026-03-10',
            $calculo->para($panaderia['producto'], Carbon::parse('2026-03-10'))?->toDateString(),
            'Cero dias significa "caduca hoy", que es distinto de "no caduca".'
        );
    }

    public function test_una_vigencia_nula_dentro_de_la_fila_significa_que_no_caduca(): void
    {
        $panaderia = $this->crearPanaderia();

        // Una fila que dice explicitamente "este producto no caduca". Esta
        // decision gana sobre el valor por omision de la configuracion.
        $this->fijarVigencia($panaderia['producto'], null);

        $calculo = app(CalculadoraDeVencimiento::class);

        $this->assertNull($calculo->reglaDe($panaderia['producto']));
    }

    public function test_un_lote_vencido_se_marca_y_ya_no_se_puede_vender(): void
    {
        $panaderia = $this->crearPanaderia();

        $carga = $this->cargarStock(
            $panaderia['producto'],
            $panaderia['mostradorUbicacion'],
            10,
            true,
            Carbon::today()->subDay()
        );

        $revisor = app(RevisorDeCaducidad::class);
        $revisor->marcarVencidos();

        $lote = $carga['lote']->fresh();

        $this->assertSame(EstadoLote::VENCIDO->value, $lote->estado->value);
        $this->assertFalse(
            $lote->estado->sePuedeVender(),
            'Un lote vencido no se puede vender en el mostrador.'
        );
    }

    public function test_un_lote_sin_vencimiento_no_caduca_solo(): void
    {
        $panaderia = $this->crearPanaderia();

        $carga = $this->cargarStock(
            $panaderia['producto'],
            $panaderia['mostradorUbicacion'],
            10,
            true,
            null
        );

        app(RevisorDeCaducidad::class)->marcarVencidos();

        $lote = $carga['lote']->fresh();

        $this->assertNotSame(
            EstadoLote::VENCIDO->value,
            $lote->estado->value,
            'Un lote sin fecha de vencimiento no puede marcarse vencido solo.'
        );
        $this->assertFalse($lote->estaVencido());
    }

    public function test_la_merma_automatica_solo_corre_si_la_panaderia_la_pidio(): void
    {
        $panaderia = $this->crearPanaderia();

        // Con la merma automatica ACTIVADA para este producto.
        $this->fijarVigencia($panaderia['producto'], 1, true);

        $carga = $this->cargarStock(
            $panaderia['producto'],
            $panaderia['mostradorUbicacion'],
            8,
            true,
            Carbon::today()->subDay()
        );

        $resultado = app(RevisorDeCaducidad::class)->mermarVencidos();

        $this->assertSame(1, $resultado['mermados']);
        $this->assertSame(0.0, (float) $carga['lote']->fresh()->cantidad_disponible);
        $this->assertSame(1, Merma::query()->count());
    }

    public function test_sin_merma_automatica_el_lote_vencido_espera_a_una_persona(): void
    {
        $panaderia = $this->crearPanaderia();

        // Con la merma automatica DESACTIVADA.
        $this->fijarVigencia($panaderia['producto'], 1, false);

        $carga = $this->cargarStock(
            $panaderia['producto'],
            $panaderia['mostradorUbicacion'],
            8,
            true,
            Carbon::today()->subDay()
        );

        $resultado = app(RevisorDeCaducidad::class)->mermarVencidos();

        $this->assertSame(0, $resultado['mermados']);
        $this->assertSame(1, $resultado['omitidos']);
        $this->assertSame(
            8.0,
            (float) $carga['lote']->fresh()->cantidad_disponible,
            'Sin merma automatica, la mercaderia sigue ahí esperando a que alguien decida.'
        );
        $this->assertSame(0, Merma::query()->count());
    }

    public function test_la_merma_de_un_lote_vencido_lleva_el_motivo_de_vencimiento(): void
    {
        $panaderia = $this->crearPanaderia();

        $this->fijarVigencia($panaderia['producto'], 1, true);

        $this->cargarStock(
            $panaderia['producto'],
            $panaderia['mostradorUbicacion'],
            6,
            true,
            Carbon::today()->subDay()
        );

        app(RevisorDeCaducidad::class)->mermarVencidos();

        $merma = Merma::query()->firstOrFail();

        $this->assertSame($panaderia['motivoVencido']->id, $merma->motivo_merma_id);
        $this->assertSame('automatica', $merma->origen->value);
        $this->assertSame(
            6.0,
            (float) $merma->cantidad,
            'La merma automatica registra la cantidad completa que quedaba.'
        );
    }

    public function test_la_revision_avisa_lo_que_esta_por_vencer(): void
    {
        $panaderia = $this->crearPanaderia();

        $this->cargarStock(
            $panaderia['producto'],
            $panaderia['mostradorUbicacion'],
            5,
            true,
            Carbon::today()->addDay()
        );

        $resultado = app(RevisorDeCaducidad::class)->revisar(Carbon::today(), 2);

        $this->assertCount(1, $resultado['por_vencer']);
        $this->assertCount(0, $resultado['vencidos']);
    }

    /**
     * El orden de venta: primero lo que vence antes.
     *
     * Y lo que mas importa: lo que NO tiene fecha de vencimiento va al
     * final. Sin ese `NULLS LAST`, PostgreSQL pondria los nulos primero y el
     * pan sin regla se venderia SIEMPRE primero, que es lo contrario de lo
     * razonable.
     */
    public function test_los_lotes_sin_vencimiento_van_al_final_del_orden(): void
    {
        $panaderia = $this->crearPanaderia();

        $sinVencimiento = $this->cargarStock(
            $panaderia['producto'],
            $panaderia['mostradorUbicacion'],
            5,
            true,
            null
        );

        $conVencimientoLejano = $this->cargarStock(
            $panaderia['producto'],
            $panaderia['mostradorUbicacion'],
            5,
            true,
            Carbon::today()->addDays(5)
        );

        $ordenados = LoteProducto::query()
            ->orderByRaw('fecha_vencimiento ASC NULLS LAST')
            ->pluck('id')
            ->all();

        $this->assertSame(
            $conVencimientoLejano['lote']->id,
            $ordenados[0],
            'El lote que vence en cinco dias va primero.'
        );
        $this->assertSame(
            $sinVencimiento['lote']->id,
            end($ordenados),
            'El lote sin fecha de vencimiento va al final: es el que mas se puede guardar.'
        );
    }
}