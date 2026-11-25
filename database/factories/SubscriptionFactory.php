<?php

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\Franchisee;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    /**
     * An active subscription that started today and runs for a month.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'franchisee_id' => Franchisee::factory(),
            'plan_id' => Plan::factory(),
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addMonth()->toDateString(),
            'source' => 'manual',
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => now()->subMonths(2)->toDateString(),
            'ends_at' => now()->subMonth()->toDateString(),
        ]);
    }
}
