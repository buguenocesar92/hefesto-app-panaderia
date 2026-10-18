<?php

declare(strict_types=1);

namespace Tests\Feature\Produccion;

use App\Models\LoteProducto;
use App\Models\Merma;
use App\Services\Produccion\CerradorDeSobrante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * El sobrante del turno anterior.
 *
 * POR QUE ESTO TIENE SU PROPIA PRUEBA
 * ----------------------------------
 * Sin este paso, el pan que queda de ayer se queda parado en un cajon hasta
 * que alguien lo ve, y para entonces ya vencio y se perdio sin que nadie lo
 * escribiera. Esa es la merma invisible, y es justo lo que el sistema existe
 * para hacer visible.
 *
 * Con el cierre de turno, el sobrante NO tiene una tercera salida: o se
 * merma con su motivo, o pasa a la bodega y aparece en el inventario del dia
 * siguiente.
 */
class SobranteDelTurnoTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_lote_vencido_se_merma_y_no_pasa_a_la_bodega(): void
    {
        $panaderia = $this->crearPanaderia();

        $carga = $this->cargarStock(
            $panaderia['producto'],
            $panaderia['mostradorUbicacion'],
            6,
            true,
            // Vencio ayer: este sobrante ya no se puede vender.
            Carbon::today()->subDay()
        );

        $resultado = app(CerradorDeSobrante::class)->cerrarTurno(Carbon::today(), $panaderia['bodega']);

        $this->assertSame(6.0, $resultado['total_sobrante']);
        $this->assertCount(1, $resultado['mermados']);
        $this->assertCount(0, $resultado['traspasados']);

        $lote = $carga['lote']->fresh();

        $this->assertSame(
            0.0,
            (float) $lote->cantidad_disponible,
            'Un lote vencido no se traspasa: se merma.'
        );
        $this->assertSame(
            $panaderia['mostradorUbicacion']->id,
            $lote->ubicacion_id,
            'Un lote vencido no se traspasa a la bodega: se merma donde estaba.'
        );
    }

    public function test_el_lote_que_todavia_sirve_pasa_a_la_bodega(): void
    {
        $panaderia = $this->crearPanaderia();

        $carga = $this->cargarStock(
            $panaderia['producto'],
            $panaderia['mostradorUbicacion'],
            6,
            true,
            // Vence manana: todavia sirve.
            Carbon::today()->addDay()
        );

        $resultado = app(CerradorDeSobrante::class)->cerrarTurno(Carbon::today(), $panaderia['bodega']);

        $this->assertCount(1, $resultado['traspasados']);
        $this->assertCount(0, $resultado['mermados']);

        $lote = $carga['lote']->fresh();

        $this->assertSame(6.0, (float) $lote->cantidad_disponible);
        $this->assertSame(
            $panaderia['bodega']->id,
            $lote->ubicacion_id,
            'El sobrante que sirve queda en la bodega, listo para el dia siguiente.'
        );
    }

    public function test_el_sobrante_queda_en_el_inventario_del_dia_siguiente(): void
    {
        $panaderia = $this->crearPanaderia();

        $carga = $this->cargarStock(
            $panaderia['producto'],
            $panaderia['mostradorUbicacion'],
            4,
            true,
            Carbon::today()->addDay()
        );

        app(CerradorDeSobrante::class)->cerrarTurno(Carbon::today(), $panaderia['bodega']);

        // El inventario del dia siguiente lo tiene que mostrar: 4 unidades,
        // no cero. Este es el punto de todo el paso.
        $this->assertSame(
            4.0,
            $this->registro()->stockDeArticulo($panaderia['producto']->id)
        );
        $this->assertSame(1, LoteProducto::query()->where('cantidad_disponible', '>', 0)->count());
    }

    public function test_la_merma_del_sobrante_lleva_su_motivo_y_descuenta_el_stock(): void
    {
        $panaderia = $this->crearPanaderia();

        $this->cargarStock(
            $panaderia['producto'],
            $panaderia['mostradorUbicacion'],
            3,
            true,
            Carbon::today()->subDays(2)
        );

        app(CerradorDeSobrante::class)->cerrarTurno(Carbon::today(), $panaderia['bodega']);

        $merma = Merma::query()->firstOrFail();

        $this->assertSame($panaderia['motivoVencido']->id, $merma->motivo_merma_id);
        $this->assertSame(3.0, (float) $merma->cantidad);
        $this->assertSame(
            0.0,
            $this->registro()->stockDeArticulo($panaderia['producto']->id),
            'El stock tiene que bajar: una perdida que no descuenta es una perdida inventada.'
        );
    }

    public function test_un_lote_sin_vencimiento_no_se_merma_nunca_por_sobrante(): void
    {
        $panaderia = $this->crearPanaderia();

        $carga = $this->cargarStock(
            $panaderia['producto'],
            $panaderia['mostradorUbicacion'],
            5,
            true,
            // Sin fecha: no hay ninguna regla escrita que diga que caduca.
            null
        );

        $resultado = app(CerradorDeSobrante::class)->cerrarTurno(Carbon::today(), $panaderia['bodega']);

        $this->assertCount(0, $resultado['mermados']);
        $this->assertCount(1, $resultado['traspasados']);
        $this->assertSame(0, Merma::query()->count());
        $this->assertSame(5.0, (float) $carga['lote']->fresh()->cantidad_disponible);
    }

    public function test_un_traslado_no_engaña_al_libro_de_movimientos(): void
    {
        $panaderia = $this->crearPanaderia();

        $this->cargarStock(
            $panaderia['producto'],
            $panaderia['mostradorUbicacion'],
            10,
            true,
            Carbon::today()->addDay()
        );

        $saldoAntes = $this->registro()->stockDeArticulo($panaderia['producto']->id);

        app(CerradorDeSobrante::class)->cerrarTurno(Carbon::today(), $panaderia['bodega']);

        $saldoDespues = $this->registro()->stockDeArticulo($panaderia['producto']->id);

        // Mover un lote del mostrador a la bodega no hace que la mercaderia
        // entre de nuevo al inventario. Si contara, el saldo se doblaria.
        $this->assertSame($saldoAntes, $saldoDespues);
    }
}