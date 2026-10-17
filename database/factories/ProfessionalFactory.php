<?php

namespace Database\Factories;

use App\Models\Professional;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Professional>
 */
class ProfessionalFactory extends Factory
{
    /**
     * Synthetic data only. tenant_id follows the base unit.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'base_unit_id' => Unit::factory(),
            'tenant_id' => fn (array $attributes) => Unit::withoutTenantScope()->whereKey($attributes['base_unit_id'])->value('tenant_id'),
            'name' => fake()->name(),
        ];
    }
}
