<?php

namespace Database\Factories;

use App\Models\Sector;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sector>
 */
class SectorFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'unit_id' => Unit::factory(),
            'tenant_id' => fn (array $attributes) => Unit::withoutTenantScope()->where('id', $attributes['unit_id'])->firstOrFail()->tenant_id,
            'name' => fake()->unique()->randomElement(['UTI', 'Pronto-socorro', 'Centro cirúrgico', 'Enfermaria', 'Pediatria', 'Maternidade']).' '.fake()->unique()->numberBetween(1, 999),
        ];
    }
}
