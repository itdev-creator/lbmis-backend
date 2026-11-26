<?php

namespace Database\Factories;

use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\Franchisee;
use App\Models\Payment;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * A pending first payment for a plan.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cart_id' => Payment::newCartId(PaymentPurpose::Subscription),
            'purpose' => PaymentPurpose::Subscription,
            'franchisee_id' => Franchisee::factory(),
            'plan_id' => Plan::factory(),
            'amount' => '99.00',
            'currency' => 'MYR',
            'status' => PaymentStatus::Pending,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::Paid,
            'po_id' => 'M161-PO-'.fake()->unique()->numerify('######'),
            'gateway_status' => '88 - Transferred',
            'paid_at' => now(),
        ]);
    }
}
