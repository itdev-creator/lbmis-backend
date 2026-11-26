<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\Gkash\GkashException;
use App\Services\Payments\PaymentReconciler;
use Illuminate\Console\Command;

/**
 * Runs every 15 minutes (routes/console.php). Payments still pending after 10 minutes
 * are checked with GKash, in case its callback never reached us.
 */
class ReconcilePayments extends Command
{
    protected $signature = 'lbmis:reconcile-payments';

    protected $description = 'Check pending GKash payments and record their final status';

    public function handle(PaymentReconciler $reconciler): int
    {
        $pending = Payment::query()
            ->where('status', PaymentStatus::Pending->value)
            ->where('created_at', '<=', now()->subMinutes(10))
            ->where('created_at', '>=', now()->subDays(3))
            ->orderBy('id')
            ->limit(100)
            ->get();

        foreach ($pending as $payment) {
            try {
                $payment = $reconciler->check($payment);
                $this->line("{$payment->cart_id}: {$payment->status->value}");
            } catch (GkashException $e) {
                $this->error("{$payment->cart_id}: {$e->getMessage()}");
            }
        }

        // Older than 3 days and still pending: the customer never finished paying.
        Payment::query()
            ->where('status', PaymentStatus::Pending->value)
            ->where('created_at', '<', now()->subDays(3))
            ->update(['status' => PaymentStatus::Failed->value, 'description' => 'Abandoned: no result from GKash within 3 days']);

        return self::SUCCESS;
    }
}
