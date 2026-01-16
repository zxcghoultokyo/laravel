<?php

namespace App\Console;

use App\Jobs\GenerateCategoryScenariosJob;
use App\Jobs\GenerateCategoryScriptsJob;
use App\Jobs\SyncHoroshopProductsJob;
use App\Jobs\IncrementalProductSyncJob;
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

        // 5. Incremental sync every 4 hours (faster updates for price/stock changes)
        $schedule->job(new IncrementalProductSyncJob(true, true))
            ->cron('0 */4 * * *')  // Every 4 hours: 00:00, 04:00, 08:00, 12:00, 16:00, 20:00
            ->environments(['production'])
            ->withoutOverlapping();

        // Category scenarios
        $schedule->job(new GenerateCategoryScenariosJob())
            ->dailyAt('06:00');

        $schedule->job(new GenerateCategoryScriptsJob())
            ->weeklyOn(1, '07:00');
            
        // 6. Sync orders from Horoshop (twice a day: morning + evening)
        $schedule->command('orders:sync', ['--days' => 3, '--update-counts'])
            ->twiceDaily(8, 20)
            ->environments(['production'])
            ->withoutOverlapping();
    }

    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');
        $this->command(\App\Console\Commands\SearchEvaluate::class);
        require base_path('routes/console.php');
    }
}
