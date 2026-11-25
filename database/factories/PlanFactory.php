<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->word()).' plan',
            'code' => strtoupper(fake()->unique()->lexify('PLAN??')),
            'description' => fake()->sentence(),
            'price' => fake()->randomElement([49, 99, 149]),
            'billing_period' => 'monthly',
            'modules' => ['documents'],
            'is_active' => true,
        ];
    }

    /**
     * @param  list<string>  $modules
     */
    public function withModules(array $modules): static
    {
        return $this->state(fn (array $attributes) => ['modules' => $modules]);
    }
}
