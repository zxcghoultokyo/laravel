<?php

namespace App\Console;

use App\Jobs\GenerateCategoryScenariosJob;
use App\Jobs\GenerateCategoryScriptsJob;
use App\Jobs\SyncHoroshopProductsJob;
use App\Jobs\SyncBrandsJob;
use App\Jobs\AnalyzeProductsWithAiJob;
use App\Jobs\IndexProductsToMeiliJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        \App\Console\Commands\SyncHoroshopProducts::class,
        \App\Console\Commands\BuildProductAiIndex::class,
        \App\Console\Commands\SearchEvaluate::class,
        \App\Console\Commands\SearchSeedEval::class,
        \App\Console\Commands\SyncBrandsCommand::class,
    ];

    protected function schedule(Schedule $schedule): void
    {
        // 1. Sync products from Horoshop
        $schedule->job(new SyncHoroshopProductsJob())
            ->dailyAt('03:00');

        // 2. Sync brands after products sync (with 30min delay)
        $schedule->job(new SyncBrandsJob())
            ->dailyAt('03:30');

        // 3. AI enrichment for new products (after sync)
        $schedule->job(new AnalyzeProductsWithAiJob())
            ->dailyAt('04:00')
            ->environments(['production']);

        // 4. Reindex Meilisearch after enrichment
        $schedule->job(new IndexProductsToMeiliJob())
            ->dailyAt('05:00')
            ->environments(['production']);

        // Category scenarios
        $schedule->job(new GenerateCategoryScenariosJob())
            ->dailyAt('06:00');

        $schedule->job(new GenerateCategoryScriptsJob())
            ->weeklyOn(1, '07:00');
    }

    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');
        $this->command(\App\Console\Commands\SearchEvaluate::class);
        require base_path('routes/console.php');
    }
}
