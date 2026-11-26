<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Carbon\CarbonImmutable;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Types after casts (PHPStan reads only the migration otherwise):
 *
 * @property SubscriptionStatus $status
 * @property CarbonImmutable|null $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property string|null $gkash_epkey
 * @property bool $auto_renew
 * @property int $renewal_attempts
 */
#[Fillable([
    'franchisee_id', 'plan_id', 'status', 'starts_at', 'ends_at', 'source', 'reference',
    'gkash_epkey', 'auto_renew', 'renewal_attempts', 'last_renewal_error',
])]
#[Hidden(['gkash_epkey'])]
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'starts_at' => 'date',
            'ends_at' => 'date',
            // The saved-card token from GKash is encrypted with APP_KEY in the database.
            'gkash_epkey' => 'encrypted',
            'auto_renew' => 'boolean',
            'renewal_attempts' => 'integer',
        ];
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
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function canAutoRenew(): bool
    {
        return $this->auto_renew && $this->gkash_epkey !== null && $this->gkash_epkey !== '';
    }
}
