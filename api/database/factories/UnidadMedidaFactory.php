<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\UnidadMedida;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<UnidadMedida>
 */
class UnidadMedidaFactory extends Factory
{
    protected $model = UnidadMedida::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => Tenant::factory(),
            'codigo' => strtoupper($this->faker->unique()->lexify('???')),
            'nombre' => $this->faker->word(),
            'unidades_por_base' => 1,
        ];
    }

    /** Una unidad que vale N unidades base. */
    public function factorDe(float $factor): static
    {
        return $this->state(fn (): array => ['unidades_por_base' => $factor]);
    }

    /** Unidad base: 1 vale 1. */
    public function base(): static
    {
        return $this->state(fn (): array => [
            'codigo' => 'UN',
            'nombre' => 'Unidad',
            'unidades_por_base' => 1,
        ]);
    }

    public function de(Tenant $panaderia): static
    {
        return $this->state(fn (): array => ['tenant_id' => $panaderia->id]);
    }
}