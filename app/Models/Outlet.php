<?php

namespace App\Models;

use App\Enums\OutletStage;
use App\Enums\OutletType;
use Database\Factories\OutletFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'code', 'name', 'brand_id', 'type', 'franchisee_id', 'stage',
    'address', 'state', 'latitude', 'longitude', 'opened_at', 'closed_at',
])]
class Outlet extends Model
{
    /** @use HasFactory<OutletFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => OutletType::class,
            'stage' => OutletStage::class,
            'opened_at' => 'date',
            'closed_at' => 'date',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsTo<Franchisee, $this>
     */
    public function franchisee(): BelongsTo
    {
        return $this->belongsTo(Franchisee::class);
    }

    /**
     * Staff assigned to this outlet.
     *
     * @return BelongsToMany<User, $this>
     */
    public function assignedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * Only the outlets this user may see, based on their role's outlet scope.
     *
     * @param  Builder<Outlet>  $query
     * @return Builder<Outlet>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return match ($user->outletScope()) {
            'all' => $query,
            'assigned' => $query->whereHas('assignedUsers', fn ($q) => $q->whereKey($user->getKey())),
            'owned' => $user->franchisee_id === null
                ? $query->whereRaw('1 = 0')
                : $query->where('franchisee_id', $user->franchisee_id),
            default => $query->whereRaw('1 = 0'),
        };
    }

    public function isVisibleTo(User $user): bool
    {
        return static::query()->visibleTo($user)->whereKey($this->getKey())->exists();
    }
}
