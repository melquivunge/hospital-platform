<?php

namespace Database\Factories;

use App\Models\JobFunction;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobFunction>
 */
class JobFunctionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => 'Função '.fake()->unique()->word(),
        ];
    }
}
