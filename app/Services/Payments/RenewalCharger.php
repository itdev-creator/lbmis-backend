<?php

namespace App\Services\Payments;

use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Payment;
use App\Models\Subscription;
use App\Services\Gkash\GkashClient;
use App\Services\Gkash\GkashException;
use Illuminate\Support\Str;

/**
 * Charges the card saved at the first payment when a paid plan is due for renewal.
 */
class RenewalCharger
{
    public function __construct(
        private readonly GkashClient $gkash,
        private readonly PaymentProcessor $processor,
        private readonly PaymentReconciler $reconciler,
    ) {}

    public function charge(Subscription $subscription): Payment
    {
        $plan = $subscription->plan;

        if ($plan === null || ! $subscription->canAutoRenew()) {
            throw new GkashException('This subscription has no saved card to renew with.');
        }

        $payment = Payment::query()->create([
            'cart_id' => Payment::newCartId(PaymentPurpose::Renewal),
            'purpose' => PaymentPurpose::Renewal,
            'franchisee_id' => $subscription->franchisee_id,
            'plan_id' => $plan->id,
            'subscription_id' => $subscription->id,
            'amount' => $plan->price,
            'currency' => GkashClient::CURRENCY,
            'status' => PaymentStatus::Pending,
        ]);

        try {
            $result = $this->gkash->chargeSavedCard(
                $payment->cart_id,
                (string) $payment->amount,
                (string) $subscription->gkash_epkey,
                $plan->recurringType(),
                Str::limit('LBMIS '.$plan->name.' plan renewal', 100, ''),
            );

            if ($this->gkash->verifyResult($result)) {
                $payment = $this->processor->apply($payment, $result, 'recurring');
            } else {
                // The answer's signature did not check out: trust only GKash's own status query.
                $payment = $this->reconciler->check($payment);
            }
        } catch (GkashException $e) {
            $payment->update(['description' => Str::limit($e->getMessage(), 250)]);
            $this->recordFailure($subscription, $e->getMessage());

            return $payment->refresh();
        }

        if ($payment->status === PaymentStatus::Failed) {
            $this->recordFailure($subscription, (string) ($payment->gateway_status ?? 'Payment failed'));
        }

        return $payment;
    }

    private function recordFailure(Subscription $subscription, string $reason): void
    {
        $subscription->refresh();
        $subscription->renewal_attempts++;
        $subscription->last_renewal_error = Str::limit($reason, 250);

        $maxAttempts = (int) config('lbmis.renewals.max_attempts', 3);
        if ($subscription->renewal_attempts >= $maxAttempts) {
            $subscription->status = SubscriptionStatus::Expired;
            $subscription->auto_renew = false;
        }

        $subscription->save();
    }
}
