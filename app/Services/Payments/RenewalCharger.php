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
                // GKash's docs show recurring-answer signatures that don't match its own formula,
                // so if the check fails, confirm the payment with GKash's status query instead.
                $payment = $this->reconciler->check($payment);
                $this->keepNewCardToken($subscription, $payment, $result);
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

    /**
     * The status query does not return the new epkey, but the charge answer does. That answer came
     * back on our own HTTPS request to GKash, so once the query confirms the payment its token is kept;
     * otherwise the next renewal would use the old, spent token.
     *
     * @param  array<string, mixed>  $result
     */
    private function keepNewCardToken(Subscription $subscription, Payment $payment, array $result): void
    {
        $epKey = $result['epkey'] ?? null;

        if ($payment->status !== PaymentStatus::Paid
            || ! is_string($epKey) || trim($epKey) === ''
            || strcasecmp((string) ($result['cartid'] ?? ''), $payment->cart_id) !== 0) {
            return;
        }

        $subscription->refresh();
        $subscription->gkash_epkey = trim($epKey);
        $subscription->save();
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
