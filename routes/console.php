<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// =============================================
// SCHEDULED TASKS - Data Sync Pipeline
// =============================================
// Flow: Horoshop → AI Enrichment → Meilisearch → Stats
// See docs/DATA_SYNC_OVERVIEW.md for details

// ─────────────────────────────────────────────
// 1. HOROSHOP SYNC (03:00)
// ─────────────────────────────────────────────
// Sync products from Horoshop API for ALL active tenants (paid OR on trial)
Schedule::call(function () {
    // Get all tenants that can use the service (active subscription OR on trial)
    $tenants = \App\Models\Tenant::canUseService()->get();
    
    foreach ($tenants as $tenant) {
        \App\Jobs\SyncHoroshopProductsJob::dispatch($tenant->id);
    }
    
    \Illuminate\Support\Facades\Log::info('Scheduled Horoshop sync for active tenants', [
        'tenants_count' => $tenants->count(),
        'tenant_ids' => $tenants->pluck('id')->toArray(),
    ]);
})
    ->dailyAt('03:00')
    ->name('sync-all-tenants')
    ->withoutOverlapping();

// Sync brands after products sync (03:30)
Schedule::command('brands:sync --async')
    ->dailyAt('03:30')
    ->runInBackground()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/sync-brands.log'));

// Sync orders from Horoshop (twice daily: morning + evening)
Schedule::command('orders:sync --days=3 --update-counts')
    ->twiceDaily(8, 20)
    ->runInBackground()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/sync-orders.log'));

// ─────────────────────────────────────────────
// 2. AI ENRICHMENT (04:00) - after Horoshop sync
// ─────────────────────────────────────────────
// Enrich products for ALL active tenants (paid OR on trial) that have products without AI index
// This is CRITICAL for search quality - runs per-tenant to ensure coverage
Schedule::call(function () {
    // Get all tenants that can use the service AND have products
    $tenants = \App\Models\Tenant::canUseService()
        ->whereHas('products', fn($q) => $q->where('in_stock', true))
        ->get();
    
    foreach ($tenants as $tenant) {
        // Count products without AI index for this tenant
        $productsWithoutAi = \App\Models\Product::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->where('in_stock', true)
            ->whereNotIn('id', function ($q) {
                $q->select('product_id')->from('product_ai_index')->whereNotNull('keywords');
            })
            ->count();
        
        if ($productsWithoutAi > 0) {
            \Illuminate\Support\Facades\Log::info('Scheduled AI enrichment for tenant', [
                'tenant_id' => $tenant->id,
                'products_without_ai' => $productsWithoutAi,
            ]);
            
            \App\Jobs\AnalyzeProductsWithAiJob::dispatch(
                batchSize: min(100, $productsWithoutAi),
                offset: 0,
                forceReanalyze: false,
                tenantId: $tenant->id
            )->onQueue('default');
        }
    }
})
    ->dailyAt('04:00')
    ->name('ai-enrichment-all-tenants')
    ->withoutOverlapping();

// ─────────────────────────────────────────────
// 2.5. AI ENRICHMENT HEALTH CHECK (every 30 min)
// ─────────────────────────────────────────────
// Auto-restart enrichment if products remain unenriched
// This ensures enrichment completes even if worker restarts
Schedule::call(function () {
    $tenants = \App\Models\Tenant::canUseService()
        ->whereHas('products', fn($q) => $q->where('in_stock', true))
        ->get();
    
    foreach ($tenants as $tenant) {
        // Count products without AI index for this tenant
        $productsWithoutAi = \App\Models\Product::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->where('in_stock', true)
            ->whereNotIn('id', function ($q) {
                $q->select('product_id')->from('product_ai_index')->whereNotNull('keywords');
            })
            ->count();
        
        // Only dispatch if there are unenriched products AND coverage < 95%
        $totalProducts = \App\Models\Product::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->where('in_stock', true)
            ->count();
        
        $coverage = $totalProducts > 0 ? (($totalProducts - $productsWithoutAi) / $totalProducts) * 100 : 100;
        
        if ($productsWithoutAi > 0 && $coverage < 95) {
            \Illuminate\Support\Facades\Log::info('AI enrichment health check: dispatching batch', [
                'tenant_id' => $tenant->id,
                'products_without_ai' => $productsWithoutAi,
                'coverage_percent' => round($coverage, 1),
            ]);
            
            // Dispatch small batch to continue enrichment
            \App\Jobs\AnalyzeProductsWithAiJob::dispatch(
                batchSize: min(50, $productsWithoutAi),
                offset: 0,
                forceReanalyze: false,
                tenantId: $tenant->id
            )->onQueue('default');
        }
    }
})
    ->everyThirtyMinutes()
    ->name('ai-enrichment-health-check')
    ->withoutOverlapping();

// ─────────────────────────────────────────────
// 3. MEILISEARCH INDEXING (05:00) - after AI enrichment
// ─────────────────────────────────────────────
// Reindex products in Meilisearch for ALL tenants
Schedule::call(function () {
    // Full reindex for all tenants (no tenant filter = all)
    \App\Jobs\IndexProductsToMeiliJob::dispatch(null)->onQueue('default');
    
    \Illuminate\Support\Facades\Log::info('Scheduled Meilisearch full reindex started');
})
    ->dailyAt('05:00')
    ->name('meili-reindex-all')
    ->withoutOverlapping();

// ─────────────────────────────────────────────
// 3.5. COLOR DETECTION (05:30) - after Meilisearch
// ─────────────────────────────────────────────
// Auto-detect colors for products without color attribute
Schedule::command('colors:detect --limit=100')
    ->dailyAt('05:30')
    ->runInBackground()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/sync-colors.log'));

// ─────────────────────────────────────────────
// 4. STATS UPDATE (06:00) - after orders sync
// ─────────────────────────────────────────────
// Update products orders count from order_items
Schedule::command('products:update-orders-count')
    ->dailyAt('06:00')
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/sync-stats.log'));

// ─────────────────────────────────────────────
// 5. WEEKLY TASKS (Sunday 02:00)
// ─────────────────────────────────────────────
// Rebuild category index (categories rarely change)
Schedule::command('categories:rebuild')
    ->weeklyOn(0, '02:00') // Sunday at 02:00
    ->runInBackground()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/sync-categories.log'));

// Generate embeddings for semantic search (expensive, weekly)
Schedule::command('products:generate-embeddings --limit=100')
    ->weeklyOn(0, '02:30') // Sunday at 02:30
    ->runInBackground()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/sync-embeddings.log'));

// ─────────────────────────────────────────────
// TENANT MANAGEMENT
// ─────────────────────────────────────────────
// Reset monthly usage on 1st of each month at midnight
Schedule::command('tenants:reset-usage --sync')
    ->monthlyOn(1, '00:00')
    ->runInBackground()
    ->withoutOverlapping();

// Sync cached usage to database every hour
Schedule::command('tenants:sync-usage')
    ->hourly()
    ->runInBackground();

// ─────────────────────────────────────────────
// CHAT SESSION MANAGEMENT
// ─────────────────────────────────────────────
// Close inactive chat sessions (30 min timeout)
// This helps track actual conversation flow and marks sessions as "completed"
Schedule::command('chat:close-inactive --timeout=30')
    ->everyFiveMinutes()
    ->runInBackground()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/chat-sessions.log'));

