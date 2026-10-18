<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Articulo;
use App\Models\Receta;
use App\Models\Tenant;
use App\Models\UnidadMedida;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Receta>
 */
class RecetaFactory extends Factory
{
    protected $model = Receta::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => Tenant::factory(),
            'articulo_id' => Articulo::factory(),
            'nombre' => $this->faker->words(3, true),
            'version' => 1,
            'rendimiento_cantidad' => $this->faker->numberBetween(10, 60),
            'rendimiento_unidad_id' => UnidadMedida::factory(),
            'notas' => null,
            'activa' => true,
        ];
    }

    /**
     * "Esta receta rinde N unidades".
     *
     * El rendimiento es la cifra que gobierna toda la panaderia, asi que
     * tiene su propio metodo en vez de repetir el numero en cada prueba.
     */
    public function rinde(float $cantidad): static
    {
        return $this->state(fn (): array => ['rendimiento_cantidad' => $cantidad]);
    }

    public function versionN(int $version): static
    {
        return $this->state(fn (): array => ['version' => $version]);
    }

    public function de(Tenant $panaderia, Articulo $producto, UnidadMedida $unidad): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $panaderia->id,
            'articulo_id' => $producto->id,
            'rendimiento_unidad_id' => $unidad->id,
        ]);
    }
}