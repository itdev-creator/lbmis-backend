<?php

namespace App\Services\Payments;

use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Payment;
use App\Models\Subscription;
use App\Services\Gkash\GkashClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies a GKash payment result to our records. Safe to call more than once for the
 * same payment (callback, return page and status checks can all report the same result):
 * only the first final result changes anything.
 */
class PaymentProcessor
{
    /**
     * @param  array<string, mixed>  $result  verified fields from GKash (status, POID, amount, epkey, ...)
     */
    public function apply(Payment $payment, array $result, string $source): Payment
    {
        return DB::transaction(function () use ($payment, $result, $source) {
            /** @var Payment $locked */
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->getKey());

            $status = trim((string) ($result['status'] ?? ''));

            if ($locked->status->isFinal() || $status === '') {
                return $locked;
            }

            if (! $this->amountMatches($locked, $result)) {
                Log::warning('GKash result amount does not match payment', [
                    'cart_id' => $locked->cart_id, 'source' => $source, 'amount' => $result['amount'] ?? null,
                ]);

                return $locked;
            }

            $locked->fill([
                'po_id' => $this->stringOrNull($result['POID'] ?? $result['poId'] ?? null) ?? $locked->po_id,
                'gateway_status' => $status,
                'payment_type' => $this->stringOrNull($result['PaymentType'] ?? null) ?? $locked->payment_type,
                'description' => $this->stringOrNull($result['description'] ?? null),
                'gateway_response' => $this->withoutSecrets($result) + ['_source' => $source],
            ]);

            if (GkashClient::isSuccess($status)) {
                $locked->status = PaymentStatus::Paid;
                $locked->paid_at = now();
                $locked->save();
                $this->grantPlan($locked, $this->stringOrNull($result['epkey'] ?? null));
            } elseif (GkashClient::isFailure($status)) {
                $locked->status = PaymentStatus::Failed;
                $locked->save();
            } else {
                $locked->save(); // still pending; a later callback or status check settles it
            }

            return $locked;
        });
    }

    /**
     * Give the franchisee the plan the payment was for.
     */
    private function grantPlan(Payment $payment, ?string $epKey): void
    {
        $plan = $payment->plan;

        if ($plan === null) {
            return;
        }

        if ($payment->purpose === PaymentPurpose::Renewal && $payment->subscription !== null) {
            $subscription = $payment->subscription;
            // The new period follows the old one; if the plan already lapsed, it starts today.
            $from = $subscription->ends_at !== null ? $subscription->ends_at->addDay() : now();
            if ($from->lt(now()->startOfDay())) {
                $from = now();
            }
            $subscription->fill([
                'ends_at' => $plan->periodEnd($from),
                'status' => SubscriptionStatus::Active,
                'renewal_attempts' => 0,
                'last_renewal_error' => null,
                'reference' => $payment->po_id,
            ]);
            if ($epKey !== null) {
                $subscription->gkash_epkey = $epKey; // GKash issues a new token after every charge
            }
            $subscription->save();

            return;
        }

        // A new plan replaces whatever was active before.
        Subscription::query()
            ->where('franchisee_id', $payment->franchisee_id)
            ->where('status', SubscriptionStatus::Active->value)
            ->update(['status' => SubscriptionStatus::Cancelled->value, 'auto_renew' => false]);

        $subscription = Subscription::query()->create([
            'franchisee_id' => $payment->franchisee_id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => now()->toDateString(),
            'ends_at' => $plan->periodEnd(now())->toDateString(),
            'source' => 'gkash',
            'reference' => $payment->po_id,
            'gkash_epkey' => $epKey,
            'auto_renew' => $epKey !== null,
        ]);

        $payment->subscription()->associate($subscription)->save();
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function amountMatches(Payment $payment, array $result): bool
    {
        if (! isset($result['amount']) || $result['amount'] === '') {
            return true; // some status answers omit the amount
        }

        return GkashClient::signatureAmount((string) $result['amount']) === GkashClient::signatureAmount((string) $payment->amount);
    }

    /**
     * The card token and signature are not kept in the payment log.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function withoutSecrets(array $result): array
    {
        unset($result['epkey'], $result['signature']);

        return $result;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
