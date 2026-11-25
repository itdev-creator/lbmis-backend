<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Database\Factories\FranchiseeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A franchisee company. It owns franchise and Alacart outlets and has one or more
 * franchise-owner users. Paid modules come from its active subscription.
 */
#[Fillable([
    'company_name', 'registration_no', 'contact_name', 'email', 'phone',
    'address', 'autocount_debtor_code', 'is_active',
])]
class Franchisee extends Model
{
    /** @use HasFactory<FranchiseeFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Outlet, $this>
     */
    public function outlets(): HasMany
    {
        return $this->hasMany(Outlet::class);
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * The subscription in force today: active, started, and not yet ended.
     */
    public function activeSubscription(): ?Subscription
    {
        $today = now()->toDateString();

        return $this->subscriptions()
            ->with('plan')
            ->where('status', SubscriptionStatus::Active->value)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhereDate('starts_at', '<=', $today))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhereDate('ends_at', '>=', $today))
            ->latest('starts_at')
            ->first();
    }

    public function hasModule(string $module): bool
    {
        $plan = $this->activeSubscription()?->plan;

        return $plan !== null && $plan->includes($module);
    }
}
