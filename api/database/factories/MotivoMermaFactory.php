<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MotivoMerma;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MotivoMerma>
 */
class MotivoMermaFactory extends Factory
{
    protected $model = MotivoMerma::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => Tenant::factory(),
            'codigo' => strtolower($this->faker->unique()->lexify('????')),
            'nombre' => $this->faker->words(2, true),
            'descripcion' => null,
            'merma_por_vencimiento' => false,
            'activo' => true,
        ];
    }

    /**
     * El motivo `vencido`.
     *
     * Tiene un codigo FIJO, no uno aleatorio, porque el codigo es lo que
     * busca el trabajo programado.
     */
    public function vencido(): static
    {
        return $this->state(fn (): array => [
            'codigo' => MotivoMerma::CODIGO_VENCIMIENTO,
            'nombre' => 'Vencido',
            'descripcion' => 'La mercaderia paso su fecha de vencimiento.',
            'merma_por_vencimiento' => true,
        ]);
    }

    public function de(Tenant $panaderia): static
    {
        return $this->state(fn (): array => ['tenant_id' => $panaderia->id]);
    }
}