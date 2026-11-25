<?php

namespace Database\Factories;

use App\Models\Franchisee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Franchisee>
 */
class FranchiseeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_name' => fake()->company().' Sdn Bhd',
            'registration_no' => (string) fake()->numerify('20##01######'),
            'contact_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->numerify('01#-#######'),
            'address' => fake()->address(),
            'is_active' => true,
        ];
    }
}
