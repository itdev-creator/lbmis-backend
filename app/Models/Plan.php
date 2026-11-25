<?php

namespace App\Models;

use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function priceLabel(): string
    {
        $period = $this->billing_period === 'yearly' ? 'year' : 'month';

        return 'RM '.number_format((float) $this->price, 2).' / '.$period;
    }
}
