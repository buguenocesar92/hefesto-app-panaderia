<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Articulo;
use App\Models\Receta;
use App\Models\RecetaIngrediente;
use App\Models\UnidadMedida;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RecetaIngrediente>
 */
class RecetaIngredienteFactory extends Factory
{
    protected $model = RecetaIngrediente::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => null,
            'receta_id' => Receta::factory(),
            'insumo_id' => Articulo::factory(),
            'cantidad' => $this->faker->numberBetween(100, 2000),
            'unidad_id' => UnidadMedida::factory(),
            // 0 por omision: una merma que nadie declaro no se inventa.
            'merma_porcentaje' => 0,
        ];
    }

    /** Con merma estimada, para probar el calculo de rendimiento. */
    public function conMerma(float $porcentaje): static
    {
        return $this->state(fn (): array => ['merma_porcentaje' => $porcentaje]);
    }

    public function cantidadDe(float $cantidad): static
    {
        return $this->state(fn (): array => ['cantidad' => $cantidad]);
    }

    public function de(
        Receta $receta,
        Articulo $insumo,
        UnidadMedida $unidad
    ): static {
        return $this->state(fn (): array => [
            'tenant_id' => $receta->tenant_id,
            'receta_id' => $receta->id,
            'insumo_id' => $insumo->id,
            'unidad_id' => $unidad->id,
        ]);
    }
}