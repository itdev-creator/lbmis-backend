<?php

namespace Database\Factories;

use App\Enums\OutletStage;
use App\Enums\OutletType;
use App\Models\Franchisee;
use App\Models\Outlet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Outlet>
 */
class OutletFactory extends Factory
{
    /**
     * A corporate (own company) outlet by default.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'LB'.fake()->unique()->numerify('####'),
            'name' => 'LaundryBar '.fake()->city(),
            'type' => OutletType::Own,
            'franchisee_id' => null,
            'stage' => OutletStage::Operating,
            'address' => fake()->address(),
            'state' => fake()->randomElement(['Selangor', 'Kuala Lumpur', 'Johor', 'Penang', 'Perak']),
            'opened_at' => fake()->dateTimeBetween('-3 years', '-1 month'),
        ];
    }

    public function franchise(?Franchisee $franchisee = null): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => OutletType::Franchise,
            'franchisee_id' => $franchisee !== null ? $franchisee->id : Franchisee::factory(),
        ]);
    }
}
