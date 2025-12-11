<?php

use App\Jobs\SyncHoroshopProductsJob;
use Illuminate\Console\Scheduling\Schedule;

protected $commands = [
    \App\Console\Commands\SyncHoroshopProducts::class,
];

protected $commands = [
    \App\Console\Commands\BuildProductAiIndex::class,
];

protected function schedule(Schedule $schedule): void
{
    $schedule->job(new SyncHoroshopProductsJob())
        ->dailyAt('03:00'); // можна змінити час
}
