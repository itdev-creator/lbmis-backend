<?php

use Illuminate\Support\Facades\Schedule;

/*
| The scheduler container on the server runs `php artisan schedule:work`.
*/

// Charge saved cards for plans due today; expire plans that ended without renewing.
Schedule::command('lbmis:charge-renewals')
    ->dailyAt('09:00')
    ->timezone('Asia/Kuala_Lumpur')
    ->withoutOverlapping()
    ->onOneServer();

// Settle payments whose GKash callback never arrived.
Schedule::command('lbmis:reconcile-payments')
    ->everyFifteenMinutes()
    ->withoutOverlapping();
