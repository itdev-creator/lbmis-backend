<?php

namespace App\Providers;

use App\Http\Middleware\EnsureModuleAccess;
use App\Http\Middleware\EnsureUserIsActive;
use App\Services\Gkash\GkashClient;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Built from config/services.php (GKASH_* settings) when first needed.
        $this->app->bind(GkashClient::class, fn () => GkashClient::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Re-check these route middlewares on every Livewire request, not only on page load,
        // so a deactivated user or a removed permission takes effect immediately.
        Livewire::addPersistentMiddleware([
            EnsureUserIsActive::class,
            EnsureModuleAccess::class,
            Authorize::class,
        ]);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
