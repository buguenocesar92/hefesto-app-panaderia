<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        $nombre = $this->faker->company() . ' Panaderia';

        return [
            'id' => (string) Str::uuid(),
            'slug' => Str::slug($nombre, '-') . '.' . Str::lower($this->faker->word()),
            'nombre' => $nombre,
            'razon_social' => $nombre . ' Ltda.',
            'activo' => true,
        ];
    }

    /** Panaderia inactiva: no tiene que aparecer en el selector del acceso. */
    public function inactivo(): static
    {
        return $this->state(fn (): array => ['activo' => false]);
    }
}