<?php

namespace App\Console;

use App\Jobs\GenerateCategoryScenariosJob;
use App\Jobs\GenerateCategoryScriptsJob;
use App\Jobs\SyncHoroshopProductsJob;
use App\Jobs\SyncBrandsJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        \App\Console\Commands\SyncHoroshopProducts::class,
        \App\Console\Commands\BuildProductAiIndex::class,
        \App\Console\Commands\SearchEvaluate::class,
        \App\Console\Commands\SearchSeedEval::class,
    ];

    protected function schedule(Schedule $schedule): void
    {
        $schedule->job(new SyncHoroshopProductsJob())
            ->dailyAt('03:00');

        // Sync brands after products sync (with 30min delay)
        $schedule->job(new SyncBrandsJob())
            ->dailyAt('03:30');

        $schedule->job(new GenerateCategoryScenariosJob())
            ->dailyAt('04:00');

        $schedule->job(new GenerateCategoryScriptsJob())
            ->weeklyOn(1, '05:00');
    }

    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');
        $this->command(\App\Console\Commands\SearchEvaluate::class);
        require base_path('routes/console.php');
    }
}
