<?php

use App\Http\Controllers\GkashController;
use App\Http\Controllers\PaymentRedirectController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::redirect('/', '/dashboard')->name('home');

// GKash calls these. No session (its browser redirect arrives without our login cookie,
// and starting a new session there would sign the user out) and no CSRF token.
Route::withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, ValidateCsrfToken::class])
    ->prefix('payments/gkash')
    ->name('payments.gkash.')
    ->group(function () {
        Route::post('callback', [GkashController::class, 'callback'])->name('callback');
        Route::match(['get', 'post'], 'return', [GkashController::class, 'return'])->name('return');
    });

Route::middleware(['auth', 'verified', 'active'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');

    Route::livewire('outlets', 'pages::outlets.index')
        ->middleware(['can:outlets.view', 'module:outlets'])
        ->name('outlets.index');

    // Franchise owners: their plan and the modules it unlocks.
    Route::livewire('subscription', 'pages::subscription')
        ->middleware('can:subscription.view-own')
        ->name('subscription.show');

    // Payment result page (owner of the payment, or staff who can view payments).
    Route::get('payments/{cartId}/go', PaymentRedirectController::class)->name('payments.go');
    Route::livewire('payments/{cartId}', 'pages::payments.result')->name('payments.result');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::livewire('users', 'pages::admin.users')
            ->middleware('can:users.manage')
            ->name('users');

        Route::livewire('departments', 'pages::admin.departments')
            ->middleware('can:departments.manage')
            ->name('departments');

        Route::livewire('franchisees', 'pages::admin.franchisees')
            ->middleware('can:franchisees.view')
            ->name('franchisees');

        Route::livewire('plans', 'pages::admin.plans')
            ->middleware('can:plans.manage')
            ->name('plans');

        Route::livewire('payments', 'pages::admin.payments')
            ->middleware('can:payments.view')
            ->name('payments');
    });
});

require __DIR__.'/settings.php';
