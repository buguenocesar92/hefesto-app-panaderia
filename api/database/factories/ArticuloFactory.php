<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Articulo;
use App\Models\Tenant;
use App\Models\UnidadMedida;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Articulo>
 */
class ArticuloFactory extends Factory
{
    protected $model = Articulo::class;

    public function definition(): array
    {
        $unidad = UnidadMedida::factory();

        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => Tenant::factory(),
            'sku' => strtoupper($this->faker->unique()->lexify('???-????')),
            'nombre' => $this->faker->words(2, true),
            'descripcion' => null,
            'unidad_base_id' => $unidad,
            'unidad_compra_id' => $unidad,
            'unidad_venta_id' => $unidad,
            // NULL por omision: el precio es una decision comercial y este
            // sistema no la inventa.
            'precio_venta' => null,
            'stock_minimo' => null,
            'se_vende' => true,
            'es_insumo' => true,
            'activo' => true,
        ];
    }

    /** Con precio cargado, como un producto del mostrador. */
    public function conPrecio(float $precio = 1000): static
    {
        return $this->state(fn (): array => ['precio_venta' => $precio]);
    }

    /** Insumo de receta: se consume pero no se vende en el mostrador. */
    public function insumo(): static
    {
        return $this->state(fn (): array => ['se_vende' => false, 'es_insumo' => true]);
    }

    /** Producto del mostrador: se vende pero no se fabrica con el. */
    public function productoDeVenta(): static
    {
        return $this->state(fn (): array => ['se_vende' => true, 'es_insumo' => false]);
    }

    public function de(Tenant $panaderia): static
    {
        return $this->state(fn (): array => ['tenant_id' => $panaderia->id]);
    }

    /** Con las tres unidades ya resueltas. */
    public function conUnidades(
        Tenant $panaderia,
        UnidadMedida $unidad
    ): static {
        return $this->state(fn (): array => [
            'tenant_id' => $panaderia->id,
            'unidad_base_id' => $unidad->id,
            'unidad_compra_id' => $unidad->id,
            'unidad_venta_id' => $unidad->id,
        ]);
    }
}