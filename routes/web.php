<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'verified', 'active'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');

    Route::livewire('outlets', 'pages::outlets.index')
        ->middleware(['can:outlets.view', 'module:outlets'])
        ->name('outlets.index');

    // Franchise owners: their plan and the modules it unlocks.
    Route::livewire('subscription', 'pages::subscription')
        ->middleware('can:subscription.view-own')
        ->name('subscription.show');

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
    });
});

require __DIR__.'/settings.php';
