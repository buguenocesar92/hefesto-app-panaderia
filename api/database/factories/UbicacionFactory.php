<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TipoUbicacion;
use App\Models\Tenant;
use App\Models\Ubicacion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Ubicacion>
 */
class UbicacionFactory extends Factory
{
    protected $model = Ubicacion::class;

    public function definition(): array
    {
        $codigo = strtoupper($this->faker->unique()->lexify('???'));

        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => Tenant::factory(),
            'codigo' => $codigo,
            'nombre' => 'Ubicacion ' . $codigo,
            'tipo' => TipoUbicacion::BODEGA->value,
            'activo' => true,
        ];
    }

    public function bodega(): static
    {
        return $this->state(fn (): array => [
            'tipo' => TipoUbicacion::BODEGA->value,
            'nombre' => 'Bodega',
        ]);
    }

    public function mostrador(): static
    {
        return $this->state(fn (): array => [
            'tipo' => TipoUbicacion::MOSTRADOR->value,
            'nombre' => 'Mostrador',
        ]);
    }

    public function refrigeracion(): static
    {
        return $this->state(fn (): array => [
            'tipo' => TipoUbicacion::REFRIGERACION->value,
            'nombre' => 'Refrigeracion',
        ]);
    }

    public function de(Tenant $panaderia): static
    {
        return $this->state(fn (): array => ['tenant_id' => $panaderia->id]);
    }
}