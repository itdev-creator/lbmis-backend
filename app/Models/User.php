<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'phone', 'department_id', 'franchisee_id', 'is_active'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * The franchisee company a franchise owner belongs to.
     *
     * @return BelongsTo<Franchisee, $this>
     */
    public function franchisee(): BelongsTo
    {
        return $this->belongsTo(Franchisee::class);
    }

    /**
     * Outlets a staff member is assigned to.
     *
     * @return BelongsToMany<Outlet, $this>
     */
    public function assignedOutlets(): BelongsToMany
    {
        return $this->belongsToMany(Outlet::class)->withTimestamps();
    }

    /**
     * The user's single role name (each user has exactly one role).
     */
    public function roleName(): ?string
    {
        $name = $this->getRoleNames()->first();

        return is_string($name) ? $name : null;
    }

    public function roleLabel(): string
    {
        $role = $this->roleName();

        return $role === null ? 'No role' : (string) config("lbmis.roles.{$role}.label", $role);
    }

    /**
     * Which outlets the user may see: all, assigned or owned (config/lbmis.php).
     * A user with no role sees none.
     */
    public function outletScope(): string
    {
        $role = $this->roleName();

        return $role === null ? 'none' : (string) config("lbmis.roles.{$role}.outlet_scope", 'none');
    }

    public function isFranchiseOwner(): bool
    {
        return $this->hasRole('franchise-owner');
    }

    /**
     * Franchise owners need an active plan that includes a paid module.
     * Internal staff are limited by their permissions only, not by plans.
     */
    public function canUseModule(string $module): bool
    {
        $definition = config("lbmis.modules.{$module}");

        if (! is_array($definition)) {
            return false;
        }

        if (! $this->isFranchiseOwner() || empty($definition['subscription'])) {
            return true;
        }

        return $this->franchisee?->hasModule($module) ?? false;
    }
}
