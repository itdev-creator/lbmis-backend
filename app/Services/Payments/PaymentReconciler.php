<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Services\Gkash\GkashClient;

/**
 * Asks GKash for the real status of a payment and applies it.
 * Used when a callback never arrived, and to double-check recurring charges.
 */
class PaymentReconciler
{
    public function __construct(
        private readonly GkashClient $gkash,
        private readonly PaymentProcessor $processor,
    ) {}

    public function check(Payment $payment): Payment
    {
        $result = $this->gkash->queryStatus($payment->cart_id, (string) $payment->amount);

        // The query answer is not signed; make sure it is about this payment.
        if (strcasecmp((string) ($result['cartid'] ?? ''), $payment->cart_id) !== 0) {
            return $payment;
        }

        return $this->processor->apply($payment, $result, 'query');
    }
}
