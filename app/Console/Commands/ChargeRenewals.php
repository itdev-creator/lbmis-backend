<?php

namespace App\Console\Commands;

use App\Enums\PaymentPurpose;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Services\Gkash\GkashException;
use App\Services\Payments\RenewalCharger;
use Illuminate\Console\Command;

/**
 * Runs daily (routes/console.php). Charges paid plans that end today or earlier,
 * using the card saved at the first payment, and expires plans that are not renewing.
 */
class ChargeRenewals extends Command
{
    protected $signature = 'lbmis:charge-renewals';

    protected $description = 'Charge saved cards for plans that are due and expire plans that ended';

    public function handle(RenewalCharger $charger): int
    {
        $today = now()->toDateString();

        $due = Subscription::query()
            ->with('plan')
            ->where('status', SubscriptionStatus::Active->value)
            ->where('auto_renew', true)
            ->whereNotNull('gkash_epkey')
            ->whereDate('ends_at', '<=', $today)
            // at most one attempt per subscription per day
            ->whereDoesntHave('payments', fn ($q) => $q
                ->where('purpose', PaymentPurpose::Renewal->value)
                ->whereDate('created_at', $today))
            ->get();

        foreach ($due as $subscription) {
            try {
                $payment = $charger->charge($subscription);
                $this->line("{$payment->cart_id}: {$payment->status->value} ({$payment->gateway_status})");
            } catch (GkashException $e) {
                $this->error("Subscription {$subscription->id}: {$e->getMessage()}");
            }
        }

        $expired = Subscription::query()
            ->where('status', SubscriptionStatus::Active->value)
            ->where('auto_renew', false)
            ->whereNotNull('ends_at')
            ->whereDate('ends_at', '<', $today)
            ->update(['status' => SubscriptionStatus::Expired->value]);

        $this->info("Renewals attempted: {$due->count()}. Plans expired: {$expired}.");

        return self::SUCCESS;
    }
}
