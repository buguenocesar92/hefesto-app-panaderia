<?php

declare(strict_types=1);

namespace Tests\Feature\Merma;

use App\Enums\EstadoLote;
use App\Exceptions\ErrorDePanaderia;
use App\Models\LoteProducto;
use App\Models\Merma;
use App\Services\Merma\RegistradorDeMerma;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La merma: la perdida real de la panaderia.
 *
 * Lo que se comprueba aca es lo que hace que el sistema sirva de algo:
 *
 *  - La perdida DESCUENTA stock de verdad.
 *  - La perdida queda con su MOTIVO, que es obligatorio.
 *  - El saldo de la tabla de lotes y el saldo ledido del libro de
 *    movimientos SIEMPRE dan lo mismo. Si difieren, hay un saldo inventado.
 */
class MermaTest extends TestCase
{
    use RefreshDatabase;

    public function test_una_merma_descuenta_el_stock_del_lote(): void
    {
        $panaderia = $this->crearPanaderia();

        $carga = $this->cargarStock(
            $panaderia['producto'],
            $panaderia['mostradorUbicacion'],
            20
        );

        $this->registrarMerma($panaderia, $carga['lote'], 5, $panaderia['motivoQuemado']);

        $lote = $carga['lote']->fresh();

        $this->assertSame(15.0, (float) $lote->cantidad_disponible);
        $this->assertSame(
            15.0,
            $this->registro()->stockDeArticulo($panaderia['producto']->id),
            'El saldo del libro de movimientos tiene que bajar lo mismo que el del lote.'
        );
    }

    public function test_el_motivo_de_la_merma_es_obligatorio(): void
    {
        $panaderia = $this->crearPanaderia();

        $carga = $this->cargarStock(
            $panaderia['producto'],
            $panaderia['mostradorUbicacion'],
            20
        );

        $this->expectException(ErrorDePanaderia::class);
        $this->expectExceptionMessage('Una merma tiene que decir por que se perdio la mercaderia.');

        app(RegistradorDeMerma::class)->registrar([
            'articulo_id' => $panaderia['producto']->id,
            'lote_producto_id' => $carga['lote']->id,
            // Sin motivo: esto es exactamente lo que el sistema impide.
            'motivo_merma_id' => null,
            'cantidad' => 5,
        ]);
    }

    public function test_no_se_puede_mermar_mas_que_lo_que_hay_en_el_lote(): void
    {
        $panaderia = $this->crearPanaderia();

        $carga = $this->cargarStock(
            $panaderia['producto'],
            $panaderia['mostradorUbicacion'],
            3
        );

        $this->expectException(ErrorDePanaderia::class);

        app(RegistradorDeMerma::class)->registrar([
            'articulo_id' => $panaderia['producto']->id,
            'lote_producto_id' => $carga['lote']->id,
            'motivo_merma_id' => $panaderia['motivoQuemado']->id,
            'cantidad' => 10,
        ]);
    }

    public function test_la_merma_deja_una_fila_con_motivo(): void
    {
        $panaderia = $this->crearPanaderia();

        $carga = $this->cargarStock(
            $panaderia['producto'],
            $panaderia['mostradorUbicacion'],
            20
        );

        $merma = $this->registrarMerma($panaderia, $carga['lote'], 4, $panaderia['motivoQuemado']);

        $guardada = Merma::query()->find($merma->id);

        $this->assertNotNull($guardada);
        $this->assertSame(4.0, (float) $guardada->cantidad);
        $this->assertSame($panaderia['motivoQuemado']->id, $guardada->motivo_merma_id);
        $this->assertSame('manual', $guardada->origen->value);
    }

    /**
     * La garantia mas importante del inventario.
     *
     * Si estas dos cuentas dieran distinto, habria un saldo que no se
     * explica con ningun movimiento, y ese saldo es exactamente la merma que
     * el sistema no veria.
     */
    public function test_el_saldo_del_libro_siempre_cuadra_con_la_suma_de_los_lotes(): void
    {
        $panaderia = $this->crearPanaderia();

        $primero = $this->cargarStock(
            $panaderia['producto'],
            $panaderia['mostradorUbicacion'],
            30
        );

        $segundo = $this->cargarStock(
            $panaderia['producto'],
            $panaderia['mostradorUbicacion'],
            12
        );

        // Se mueven los dos lotes de forma distinta, para que la cuadra sea
        // una comprobacion de verdad y no una coincidencia.
        $this->registrarMerma($panaderia, $primero['lote'], 7, $panaderia['motivoQuemado']);
        $this->registrarMerma($panaderia, $segundo['lote'], 12, $panaderia['motivoVencido']);

        $saldoPorLotes = (float) LoteProducto::query()
            ->where('articulo_id', $panaderia['producto']->id)
            ->sum('cantidad_disponible');

        $saldoPorLibro = $this->registro()->stockDeArticulo($panaderia['producto']->id);

        $this->assertSame(
            $saldoPorLotes,
            $saldoPorLibro,
            'El saldo ledido de los lotes tiene que ser igual al saldo ledido del libro de movimientos.'
        );

        // 30 + 12 entran; 7 + 12 salen.
        $this->assertSame(23.0, $saldoPorLibro);
    }

    public function test_un_lote_que_se_queda_sin_stock_pasa_a_agotado(): void
    {
        $panaderia = $this->crearPanaderia();

        $carga = $this->cargarStock(
            $panaderia['producto'],
            $panaderia['mostradorUbicacion'],
            5
        );

        $this->registrarMerma($panaderia, $carga['lote'], 5, $panaderia['motivoQuemado']);

        $this->assertSame(
            EstadoLote::AGOTADO->value,
            $carga['lote']->fresh()->estado->value
        );
    }

    public function test_no_se_puede_editar_el_saldo_de_un_lote_a_mano(): void
    {
        $panaderia = $this->crearPanaderia();

        $carga = $this->cargarStock(
            $panaderia['producto'],
            $panaderia['mostradorUbicacion'],
            20
        );

        // Sin la marca `app.movimiento_inventario`, PostgreSQL revienta el
        // cambio. Esta es la garantia de que no hay saldo inventado.
        $this->esperarFalloDeBase(function () use ($carga): void {
            \Illuminate\Support\Facades\DB::table('lote_productos')
                ->where('id', $carga['lote']->id)
                ->update(['cantidad_disponible' => 9999]);
        });

        $this->assertSame(
            20.0,
            (float) $carga['lote']->fresh()->cantidad_disponible,
            'El saldo no deberia haber cambiado.'
        );
    }

    public function test_un_movimiento_no_se_puede_modificar_ni_borrar(): void
    {
        $panaderia = $this->crearPanaderia();

        $carga = $this->cargarStock(
            $panaderia['producto'],
            $panaderia['mostradorUbicacion'],
            20
        );

        $movimiento = $this->registro()->entrar([
            'articulo_id' => $panaderia['producto']->id,
            'cantidad' => 1,
            'ubicacion_id' => $panaderia['mostradorUbicacion']->id,
            'referencia' => 'PRUEBA',
        ], $carga['lote']);

        // El historial no se corrige: se registra el movimiento contrario.
        // Borrar el error seria borrar la evidencia.
        $this->esperarFalloDeBase(function () use ($movimiento): void {
            \Illuminate\Support\Facades\DB::table('movimientos_inventario')
                ->where('id', $movimiento->id)
                ->delete();
        });

        $this->esperarFalloDeBase(function () use ($movimiento): void {
            \Illuminate\Support\Facades\DB::table('movimientos_inventario')
                ->where('id', $movimiento->id)
                ->update(['cantidad' => 500]);
        });
    }

    /**
     * @param  array<string, mixed>  $panaderia
     */
    private function registrarMerma(
        array $panaderia,
        LoteProducto $lote,
        float $cantidad,
        $motivo
    ): Merma {
        return app(RegistradorDeMerma::class)->registrar([
            'articulo_id' => $panaderia['producto']->id,
            'lote_producto_id' => $lote->id,
            'motivo_merma_id' => $motivo->id,
            'cantidad' => $cantidad,
            'fecha' => now()->toDateString(),
        ]);
    }
}