<?php

namespace App\Services\Payments;

use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Gkash\CheckoutRedirect;
use App\Services\Gkash\GkashClient;
use App\Services\Gkash\GkashException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Starts a plan purchase for a franchise owner. Paid plans go to the GKash payment page
 * with recurringtype set, so the card is saved for automatic renewals.
 */
class SubscriptionCheckout
{
    public function __construct(private readonly GkashClient $gkash) {}

    /**
     * @return CheckoutRedirect|null null when a free plan was activated straight away
     */
    public function start(User $owner, Plan $plan): ?CheckoutRedirect
    {
        $franchisee = $owner->franchisee;

        if ($franchisee === null || ! $plan->is_active) {
            throw new GkashException('This plan cannot be bought for this account.');
        }

        if ($plan->isFree()) {
            $this->activateFreePlan($owner, $plan);

            return null;
        }

        $payment = Payment::query()->create([
            'cart_id' => Payment::newCartId(PaymentPurpose::Subscription),
            'purpose' => PaymentPurpose::Subscription,
            'franchisee_id' => $franchisee->id,
            'plan_id' => $plan->id,
            'user_id' => $owner->id,
            'amount' => $plan->price,
            'currency' => GkashClient::CURRENCY,
            'status' => PaymentStatus::Pending,
        ]);

        [$firstName, $lastName] = $this->splitName($owner->name);

        try {
            $redirect = $this->gkash->createCheckout(
                $payment->cart_id,
                (string) $payment->amount,
                route('payments.gkash.return'),
                route('payments.gkash.callback'),
                [
                    'recurringtype' => $plan->recurringType(),
                    'v_firstname' => $firstName,
                    'v_lastname' => $lastName,
                    'v_billemail' => $owner->email,
                    'v_billphone' => preg_replace('/\D+/', '', (string) $owner->phone) ?? '',
                    'v_productdesc' => Str::limit('LBMIS '.$plan->name.' plan ('.$plan->billing_period.')', 100, ''),
                    'preselection' => 'ECOMM', // card payment, so the card can be saved for renewals
                ],
            );
        } catch (GkashException $e) {
            $payment->update(['status' => PaymentStatus::Failed, 'description' => Str::limit($e->getMessage(), 250)]);

            throw $e;
        }

        if ($redirect->poId !== '') {
            $payment->update(['po_id' => $redirect->poId]);
        }

        return $redirect;
    }

    private function activateFreePlan(User $owner, Plan $plan): void
    {
        DB::transaction(function () use ($owner, $plan) {
            Subscription::query()
                ->where('franchisee_id', $owner->franchisee_id)
                ->where('status', SubscriptionStatus::Active->value)
                ->update(['status' => SubscriptionStatus::Cancelled->value, 'auto_renew' => false]);

            Subscription::query()->create([
                'franchisee_id' => $owner->franchisee_id,
                'plan_id' => $plan->id,
                'status' => SubscriptionStatus::Active,
                'starts_at' => now()->toDateString(),
                'ends_at' => null,
                'source' => 'manual',
            ]);
        });
    }

    /**
     * @return array{string, string}
     */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2) ?: [];

        return [(string) ($parts[0] ?? ''), (string) ($parts[1] ?? '')];
    }
}
