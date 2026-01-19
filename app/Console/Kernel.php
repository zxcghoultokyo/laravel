<?php

namespace App\Console;

use App\Jobs\GenerateCategoryScenariosJob;
use App\Jobs\GenerateCategoryScriptsJob;
use App\Jobs\SyncHoroshopProductsJob;
use App\Jobs\IncrementalProductSyncJob;
use App\Jobs\SyncBrandsJob;
use App\Jobs\AnalyzeProductsWithAiJob;
use App\Jobs\IndexProductsToMeiliJob;
use App\Jobs\DetectProductColorsJob;
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
        \App\Console\Commands\FixNullTenantIds::class,
    ];

    protected function schedule(Schedule $schedule): void
    {
        // 1. Sync products from Horoshop for ALL active tenants
        $schedule->call(function () {
            $tenants = \App\Models\Tenant::where('status', 'active')->get();
            foreach ($tenants as $tenant) {
                SyncHoroshopProductsJob::dispatch($tenant->id);
            }
        })
            ->dailyAt('03:00')
            ->withoutOverlapping()
            ->name('sync-all-tenants');

        // 2. Sync brands after products sync (with 30min delay)
        $schedule->job(new SyncBrandsJob())
            ->dailyAt('03:30')
            ->withoutOverlapping();

        // 3. AI enrichment for new products (production only - uses OpenAI API)
        $schedule->job(new AnalyzeProductsWithAiJob())
            ->dailyAt('04:00')
            ->environments(['production'])
            ->withoutOverlapping();

        // 4. Reindex Meilisearch after enrichment
        $schedule->job(new IndexProductsToMeiliJob())
            ->dailyAt('05:00')
            ->withoutOverlapping();

        // 4.5. Auto-detect colors for products without color (low resource usage)
        // Runs after Meilisearch reindex, processes 100 products per run
        $schedule->job(new DetectProductColorsJob(100, null, true, false))
            ->dailyAt('05:30')
            ->withoutOverlapping()
            ->name('detect-product-colors');

        // 5. Incremental sync every 4 hours (faster updates for price/stock changes)
        $schedule->job(new IncrementalProductSyncJob(true, true))
            ->cron('0 */4 * * *')  // Every 4 hours: 00:00, 04:00, 08:00, 12:00, 16:00, 20:00
            ->withoutOverlapping();

        // Category scenarios (production only - uses OpenAI)
        $schedule->job(new GenerateCategoryScenariosJob())
            ->dailyAt('06:00')
            ->environments(['production'])
            ->withoutOverlapping();

        $schedule->job(new GenerateCategoryScriptsJob())
            ->weeklyOn(1, '07:00')
            ->environments(['production'])
            ->withoutOverlapping();
            
        // 6. Sync orders from Horoshop (twice a day: morning + evening)
        $schedule->command('orders:sync', ['--days' => 3, '--update-counts'])
            ->twiceDaily(8, 20)
            ->withoutOverlapping();
    }

    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');
        $this->command(\App\Console\Commands\SearchEvaluate::class);
        require base_path('routes/console.php');
    }
}
