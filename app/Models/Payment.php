<?php

namespace App\Models;

use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One attempt to collect money through GKash. cart_id is our reference (v_cartid);
 * po_id is GKash's (POID).
 *
 * Types after casts (PHPStan reads only the migration otherwise):
 *
 * @property PaymentPurpose $purpose
 * @property PaymentStatus $status
 * @property string $amount
 * @property string|null $refunded_amount
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $refunded_at
 * @property array<string, mixed>|null $gateway_response
 */
#[Fillable([
    'cart_id', 'po_id', 'purpose', 'franchisee_id', 'plan_id', 'subscription_id', 'user_id',
    'amount', 'currency', 'status', 'gateway_status', 'payment_type', 'description',
    'paid_at', 'refunded_amount', 'refunded_at', 'gateway_response',
])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'purpose' => PaymentPurpose::class,
            'status' => PaymentStatus::class,
            'amount' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
            'gateway_response' => 'array',
        ];
    }

    /**
     * A new unique reference such as "LBMIS-SUB-20261126-7KQ2M9XA".
     * GKash allows letters, digits, hyphen, underscore and full stop.
     */
    public static function newCartId(PaymentPurpose $purpose): string
    {
        $prefix = match ($purpose) {
            PaymentPurpose::Subscription => 'SUB',
            PaymentPurpose::Renewal => 'REN',
            PaymentPurpose::BrandingFee => 'BRF',
        };

        return 'LBMIS-'.$prefix.'-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
    }

    /**
     * @return BelongsTo<Franchisee, $this>
     */
    public function franchisee(): BelongsTo
    {
        return $this->belongsTo(Franchisee::class);
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function amountLabel(): string
    {
        return 'RM '.number_format((float) $this->amount, 2);
    }
}
