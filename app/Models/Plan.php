<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A package franchise owners subscribe to. `modules` lists the keys from
 * config('lbmis.modules') that the plan unlocks.
 */
#[Fillable(['name', 'code', 'description', 'price', 'billing_period', 'modules', 'is_active'])]
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'modules' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function includes(string $module): bool
    {
        return in_array($module, (array) $this->modules, true);
    }

    public function isFree(): bool
    {
        return (float) $this->price <= 0;
    }

    /**
     * GKash recurringtype for this plan's billing period.
     */
    public function recurringType(): string
    {
        return $this->billing_period === 'yearly' ? 'ANNUAL' : 'MONTHLY';
    }

    /**
     * The last day covered when a period starts on $start.
     */
    public function periodEnd(\DateTimeInterface $start): CarbonInterface
    {
        $start = Carbon::instance($start)->startOfDay();

        return ($this->billing_period === 'yearly' ? $start->addYearNoOverflow() : $start->addMonthNoOverflow())->subDay();
    }

    public function priceLabel(): string
    {
        $period = $this->billing_period === 'yearly' ? 'year' : 'month';

        return 'RM '.number_format((float) $this->price, 2).' / '.$period;
    }
}
