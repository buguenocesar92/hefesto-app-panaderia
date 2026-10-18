<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EstadoLote;
use App\Models\Articulo;
use App\Models\LoteProducto;
use App\Models\Tenant;
use App\Models\Ubicacion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<LoteProducto>
 */
class LoteProductoFactory extends Factory
{
    protected $model = LoteProducto::class;

    public function definition(): array
    {
        $cantidad = $this->faker->numberBetween(5, 50);

        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => Tenant::factory(),
            'articulo_id' => Articulo::factory(),
            'ubicacion_id' => Ubicacion::factory(),
            'tanda_id' => null,
            'codigo' => 'LOTE-' . strtoupper($this->faker->unique()->bothify('??-????')),
            'cantidad_inicial' => $cantidad,
            'cantidad_disponible' => $cantidad,
            'fecha_produccion' => Carbon::today()->toDateString(),
            'fecha_vencimiento' => Carbon::today()->addDay()->toDateString(),
            'estado' => EstadoLote::DISPONIBLE,
        ];
    }

    /**
     * Un lote SIN fecha de vencimiento.
     *
     * Es un caso real: un producto sin regla configurada entra igual al
     * inventario. La prueba de caducidad depende de que estos lotes NO
     * caduquen solos.
     */
    public function sinVencimiento(): static
    {
        return $this->state(fn (): array => [
            'fecha_vencimiento' => null,
            'estado' => EstadoLote::DISPONIBLE,
        ]);
    }

    /** Lote que ya paso su fecha. */
    public function vencido(?Carbon $fecha = null): static
    {
        $laFecha = ($fecha ?? Carbon::today())->copy()->subDay();

        return $this->state(fn (): array => [
            'fecha_vencimiento' => $laFecha->toDateString(),
            'estado' => EstadoLote::VENCIDO,
        ]);
    }

    /** Lote con una parte del stock ya consumido. */
    public function conSaldo(float $saldo): static
    {
        return $this->state(fn (): array => ['cantidad_disponible' => $saldo]);
    }

    public function de(Tenant $panaderia, Articulo $articulo, Ubicacion $ubicacion): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $panaderia->id,
            'articulo_id' => $articulo->id,
            'ubicacion_id' => $ubicacion->id,
        ]);
    }
}