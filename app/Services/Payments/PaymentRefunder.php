<?php

namespace App\Services\Payments;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\Gkash\GkashClient;
use App\Services\Gkash\GkashException;

class PaymentRefunder
{
    public function __construct(private readonly GkashClient $gkash) {}

    /**
     * Refund a paid payment in full, or partly when $amount is smaller than what was paid.
     */
    public function refund(Payment $payment, ?string $amount = null): Payment
    {
        if ($payment->status !== PaymentStatus::Paid) {
            throw new GkashException('Only paid payments can be refunded.');
        }

        $amount ??= (string) $payment->amount;
        $alreadyRefunded = (float) ($payment->refunded_amount ?? 0);

        if ((float) $amount <= 0 || (float) $amount + $alreadyRefunded > (float) $payment->amount + 0.001) {
            throw new GkashException('The refund amount must be more than zero and not more than what is left of the payment.');
        }

        $result = $this->gkash->refund($payment->cart_id, $amount);
        $status = trim((string) ($result['status'] ?? ''));

        if (! str_starts_with($status, '00') && ! str_starts_with($status, '02')) {
            throw new GkashException('GKash refused the refund: '.($status !== '' ? $status : 'no status').' '.(string) ($result['description'] ?? ''));
        }

        $refunded = $alreadyRefunded + (float) $amount;
        $payment->fill([
            'refunded_amount' => number_format($refunded, 2, '.', ''),
            'refunded_at' => now(),
            'status' => $refunded + 0.001 >= (float) $payment->amount ? PaymentStatus::Refunded : PaymentStatus::Paid,
        ])->save();

        return $payment;
    }
}
