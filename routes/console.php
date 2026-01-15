<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// =============================================
// SCHEDULED TASKS
// =============================================

// Reset monthly usage on 1st of each month at midnight
Schedule::command('tenants:reset-usage --sync')
    ->monthlyOn(1, '00:00')
    ->runInBackground()
    ->withoutOverlapping();

// Sync cached usage to database every hour
Schedule::command('tenants:sync-usage')
    ->hourly()
    ->runInBackground();

// Sync products from Horoshop daily at 3am
Schedule::command('horoshop:sync-products')
    ->dailyAt('03:00')
    ->runInBackground()
    ->withoutOverlapping();
