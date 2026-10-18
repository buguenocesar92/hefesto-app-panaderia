<?php

declare(strict_types=1);

namespace Tests\Feature\Aislamiento;

use App\Models\Articulo;
use App\Models\LoteProducto;
use App\Models\Usuario;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Que una panaderia no vea los datos de otra.
 *
 * SI ESTAS PRUEBAS PASARAN SIN COMPROBAR NADA
 * -------------------------------------------
 * Corren contra PostgreSQL, no contra SQLite. En SQLite no hay Row Level
 * Security ni gatillos, asi que la columna `tenant_id` "filtraria" sola y
 * estas pruebas darian verde sin haber comprobado el aislamiento de verdad.
 * Por eso `phpunit.xml` fija `pgsql`.
 */
class AislamientoDeDatosTest extends TestCase
{
    use RefreshDatabase;

    public function test_una_panaderia_no_ve_los_articulos_de_otra(): void
    {
        $primera = $this->crearPanaderia('una.cl');
        $segunda = $this->crearPanaderia('dos.cl');

        $this->fijarPanaderia($primera['panaderia']->id);
        $this->assertSame(
            2,
            Articulo::query()->count(),
            'La primera panaderia deberia ver solo sus dos articulos.'
        );

        $this->fijarPanaderia($segunda['panaderia']->id);
        $this->assertSame(
            2,
            Articulo::query()->count(),
            'La segunda panaderia deberia ver solo sus dos articulos.'
        );
    }

    public function test_no_se_puede_leer_una_fila_de_otra_panaderia_por_identificador(): void
    {
        $primera = $this->crearPanaderia('una.cl');
        $segunda = $this->crearPanaderia('dos.cl');

        $this->fijarPanaderia($segunda['panaderia']->id);

        $this->assertNull(
            Articulo::query()->find($primera['producto']->id),
            'Un articulo de otra panaderia no deberia ser visible ni por su identificador.'
        );
    }

    public function test_no_se_puede_insertar_una_fila_de_otra_panaderia(): void
    {
        $primera = $this->crearPanaderia('una.cl');
        $segunda = $this->crearPanaderia('dos.cl');

        $this->fijarPanaderia($primera['panaderia']->id);

        // El `WITH CHECK` de la politica es lo que impide esto. Sin el, un
        // `INSERT` con el `tenant_id` de otra panaderia pasaria.
        $this->esperarFalloDeBase(function () use ($segunda, $primera): void {
            DB::table('articulos')->insert([
                'id' => \Illuminate\Support\Str::uuid()->toString(),
                'tenant_id' => $segunda['panaderia']->id,
                'sku' => 'INTRUSA',
                'nombre' => 'Articulo intruso',
                'unidad_base_id' => $primera['unidad']->id,
                'unidad_compra_id' => $primera['unidad']->id,
                'unidad_venta_id' => $primera['unidad']->id,
                'se_vende' => true,
                'es_insumo' => true,
                'activo' => true,
            ]);
        });
    }

    public function test_no_se_puede_mover_una_fila_entre_panaderias(): void
    {
        $primera = $this->crearPanaderia('una.cl');
        $segunda = $this->crearPanaderia('dos.cl');

        $this->fijarPanaderia($primera['panaderia']->id);

        // Sacar una fila de tu panaderia y ponerla en la de otro no tiene
        // sentido en una panaderia, y el `WITH CHECK` lo impide.
        $this->esperarFalloDeBase(function () use ($primera, $segunda): void {
            DB::table('articulos')
                ->where('id', $primera['producto']->id)
                ->update(['tenant_id' => $segunda['panaderia']->id]);
        });
    }

    public function test_leer_sin_contexto_falla_cerrado(): void
    {
        $this->crearPanaderia('una.cl');

        // Se borra la marca a proposito: esto simula una peticion sin sesion
        // que tocandoa una tabla protegida.
        DB::statement("SELECT set_config('app.tenant_id', '', false)");

        $this->esperarFalloDeBase(function (): void {
            Articulo::query()->count();
        });
    }

    public function test_una_venta_de_otra_panaderia_no_se_ve(): void
    {
        $primera = $this->crearPanaderia('una.cl');
        $segunda = $this->crearPanaderia('dos.cl');

        $ventaDeLaPrimera = $this->crearVentaDePrueba($primera);

        $this->fijarPanaderia($segunda['panaderia']->id);

        $this->assertSame(0, Venta::query()->count());
        $this->assertNull(Venta::query()->find($ventaDeLaPrimera->id));
    }

    public function test_los_lotes_de_otra_panaderia_no_se_ven(): void
    {
        $primera = $this->crearPanaderia('una.cl');
        $segunda = $this->crearPanaderia('dos.cl');

        $this->cargarStock(
            $primera['producto'],
            $primera['mostradorUbicacion'],
            10
        );

        $this->fijarPanaderia($primera['panaderia']->id);
        $this->assertSame(1, LoteProducto::query()->count());

        $this->fijarPanaderia($segunda['panaderia']->id);
        $this->assertSame(
            0,
            LoteProducto::query()->count(),
            'Los lotes de otra panaderia no deben ser visibles.'
        );
    }

    /**
     * Que una Policy que rechaza responde 404 y no 403.
     *
     * Un 403 confirma que el recurso existe, y eso ya es filtrar
     * informacion de la base de otra panaderia.
     */
    public function test_una_peticion_sin_sesion_responde_401_y_no_401_de_otra_manera(): void
    {
        $panaderia = $this->crearPanaderia('una.cl');

        $this->fijarPanaderia($panaderia['panaderia']->id);

        $respuesta = $this->getJson('/api/v1/articulos');

        $respuesta->assertUnauthorized();
    }

    /**
     * @param  array<string, mixed>  $panaderia
     */
    private function crearVentaDePrueba(array $panaderia): Venta
    {
        return Venta::query()->create([
            'tenant_id' => $panaderia['panaderia']->id,
            'numero' => 'V-PRUEBA-0001',
            'fecha' => now()->toDateString(),
            'estado' => 'cerrada',
            'bruto' => 1000,
            'descuento' => 0,
            'total' => 1000,
            'abierta_en' => now(),
            'cerrada_en' => now(),
            'vendedor_id' => $panaderia['mostrador']->id,
        ]);
    }
}