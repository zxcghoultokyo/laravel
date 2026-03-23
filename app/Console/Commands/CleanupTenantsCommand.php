<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupTenantsCommand extends Command
{
    protected $signature = 'tenants:cleanup
        {--keep=* : Tenant IDs to keep (required)}
        {--force : Actually delete data (default is dry-run)}';

    protected $description = 'Delete all tenants and their data EXCEPT the ones specified in --keep';

    public function handle(): int
    {
        $keepIds = $this->option('keep');

        if (empty($keepIds)) {
            $this->error('You must specify at least one tenant to keep: --keep=2 --keep=20');

            return 1;
        }

        $keepIds = array_map('intval', $keepIds);
        $force = $this->option('force');

        // Get tenants to delete
        $allTenants = DB::table('tenants')->select('id', 'name', 'domain')->get();
        $tenantsToDelete = $allTenants->filter(fn ($t) => ! in_array($t->id, $keepIds));
        $tenantsToKeep = $allTenants->filter(fn ($t) => in_array($t->id, $keepIds));
        $deleteIds = $tenantsToDelete->pluck('id')->toArray();

        if (empty($deleteIds)) {
            $this->info('No tenants to delete.');

            return 0;
        }

        // Show what we're keeping
        $this->info('=== TENANTS TO KEEP ===');
        foreach ($tenantsToKeep as $t) {
            $this->line("  ✅ T{$t->id}: {$t->name} ({$t->domain})");
        }

        $this->newLine();
        $this->info('=== TENANTS TO DELETE ===');
        foreach ($tenantsToDelete as $t) {
            $this->line("  ❌ T{$t->id}: {$t->name} ({$t->domain})");
        }

        // Collect counts for all tenant-related tables
        $this->newLine();
        $this->info('=== DATA TO DELETE ===');

        $stats = $this->collectStats($deleteIds);
        foreach ($stats as $table => $count) {
            $label = $count > 0 ? "<fg=red>{$count}</>" : '<fg=gray>0</>';
            $this->line("  {$table}: {$label}");
        }

        $totalRows = array_sum($stats);
        $this->newLine();
        $this->warn("Total rows to delete: {$totalRows}");

        // Show what we're keeping for safety
        $this->newLine();
        $this->info('=== DATA PRESERVED (T'.implode(', T', $keepIds).') ===');
        $keepStats = $this->collectStats($keepIds, true);
        foreach ($keepStats as $table => $count) {
            if ($count > 0) {
                $this->line("  ✅ {$table}: <fg=green>{$count}</>");
            }
        }

        if (! $force) {
            $this->newLine();
            $this->warn('🔍 DRY RUN — nothing was deleted.');
            $this->line('Run with --force to actually delete.');

            return 0;
        }

        $this->newLine();
        if (! $this->confirm("⚠️ This will PERMANENTLY delete {$totalRows} rows across ".count($stats).' tables. Continue?')) {
            $this->info('Cancelled.');

            return 0;
        }

        $this->deleteData($deleteIds);

        // Verify kept data
        $this->newLine();
        $this->info('=== VERIFICATION: T'.implode(', T', $keepIds).' data intact ===');
        $afterStats = $this->collectStats($keepIds, true);
        foreach ($afterStats as $table => $count) {
            if ($count > 0 || isset($keepStats[$table])) {
                $before = $keepStats[$table] ?? 0;
                $status = $count === $before ? '✅' : '⚠️';
                $this->line("  {$status} {$table}: {$count} (was {$before})");
            }
        }

        return 0;
    }

    private function collectStats(array $tenantIds, bool $forKeep = false): array
    {
        $productIds = fn () => DB::table('products')->whereIn('tenant_id', $tenantIds)->select('id');
        $chatSessionIds = fn () => DB::table('chat_sessions')->whereIn('tenant_id', $tenantIds)->select('id');
        $orderIds = fn () => DB::table('orders')->whereIn('tenant_id', $tenantIds)->select('id');
        $categoryIds = fn () => DB::table('categories')->whereIn('tenant_id', $tenantIds)->select('id');
        $ruleIds = fn () => DB::table('proactive_trigger_rules')->whereIn('tenant_id', $tenantIds)->select('id');

        return [
            'chat_messages' => DB::table('chat_messages')->whereIn('chat_session_id', $chatSessionIds())->count(),
            'chat_sessions' => DB::table('chat_sessions')->whereIn('tenant_id', $tenantIds)->count(),
            'chat_events' => DB::table('chat_events')->whereIn('tenant_id', $tenantIds)->count(),
            'chat_conversions' => DB::table('chat_conversions')->whereIn('merchant_id', $tenantIds)->count(),
            'chat_session_outcomes' => DB::table('chat_session_outcomes')->whereIn('merchant_id', $tenantIds)->count(),
            'chat_daily_stats' => DB::table('chat_daily_stats')->whereIn('merchant_id', $tenantIds)->count(),
            'proactive_trigger_events' => DB::table('proactive_trigger_events')->whereIn('rule_id', $ruleIds())->count(),
            'proactive_trigger_rules' => DB::table('proactive_trigger_rules')->whereIn('tenant_id', $tenantIds)->count(),
            'order_items' => DB::table('order_items')->whereIn('order_id', $orderIds())->count(),
            'orders' => DB::table('orders')->whereIn('tenant_id', $tenantIds)->count(),
            'product_ai_index' => DB::table('product_ai_index')->whereIn('product_id', $productIds())->count(),
            'product_cross_sells' => DB::table('product_cross_sells')->whereIn('product_id', $productIds())->count(),
            'product_product_tag' => DB::table('product_product_tag')->whereIn('product_id', $productIds())->count(),
            'products' => DB::table('products')->whereIn('tenant_id', $tenantIds)->count(),
            'horoshop_products' => DB::table('horoshop_products')->whereIn('tenant_id', $tenantIds)->count(),
            'rozetka_product_attribute_values' => DB::table('rozetka_product_attribute_values')
                ->whereIn('rozetka_product_id', DB::table('rozetka_products')->whereIn('tenant_id', $tenantIds)->select('id'))
                ->count(),
            'rozetka_products' => DB::table('rozetka_products')->whereIn('tenant_id', $tenantIds)->count(),
            'rozetka_category_mappings' => DB::table('rozetka_category_mappings')->whereIn('tenant_id', $tenantIds)->count(),
            'category_aliases' => DB::table('category_aliases')->whereIn('category_id', $categoryIds())->count(),
            'categories' => DB::table('categories')->whereIn('tenant_id', $tenantIds)->count(),
            'brands' => DB::table('brands')->whereIn('tenant_id', $tenantIds)->count(),
            'product_synonyms' => DB::table('product_synonyms')->whereIn('tenant_id', $tenantIds)->count(),
            'prompt_presets' => DB::table('prompt_presets')->whereIn('tenant_id', $tenantIds)->count(),
            'widget_settings' => DB::table('widget_settings')->whereIn('tenant_id', $tenantIds)->count(),
            'store_contexts' => DB::table('store_contexts')->whereIn('tenant_id', $tenantIds)->count(),
            'greetings' => DB::table('greetings')->whereIn('tenant_id', $tenantIds)->count(),
            'canned_responses' => DB::table('canned_responses')->whereIn('tenant_id', $tenantIds)->count(),
            'payments' => DB::table('payments')->whereIn('tenant_id', $tenantIds)->count(),
            'subscriptions' => DB::table('subscriptions')->whereIn('tenant_id', $tenantIds)->count(),
            'sync_logs' => DB::table('sync_logs')->whereIn('tenant_id', $tenantIds)->count(),
            'tenant_onboarding_progress' => DB::table('tenant_onboarding_progress')->whereIn('tenant_id', $tenantIds)->count(),
            'users' => DB::table('users')->whereIn('tenant_id', $tenantIds)->count(),
            'tenants' => DB::table('tenants')->whereIn('id', $tenantIds)->count(),
        ];
    }

    private function deleteData(array $deleteIds): void
    {
        $this->info('Deleting data...');

        DB::transaction(function () use ($deleteIds) {
            $productIds = DB::table('products')->whereIn('tenant_id', $deleteIds)->pluck('id')->toArray();
            $chatSessionIds = DB::table('chat_sessions')->whereIn('tenant_id', $deleteIds)->pluck('id')->toArray();
            $orderIds = DB::table('orders')->whereIn('tenant_id', $deleteIds)->pluck('id')->toArray();
            $categoryIds = DB::table('categories')->whereIn('tenant_id', $deleteIds)->pluck('id')->toArray();
            $ruleIds = DB::table('proactive_trigger_rules')->whereIn('tenant_id', $deleteIds)->pluck('id')->toArray();
            $rozetkaProductIds = DB::table('rozetka_products')->whereIn('tenant_id', $deleteIds)->pluck('id')->toArray();

            // 1. Chat messages (FK to chat_sessions)
            $this->deleteInChunks('chat_messages', 'chat_session_id', $chatSessionIds);

            // 2. Chat sessions
            $this->deleteDirect('chat_sessions', 'tenant_id', $deleteIds);

            // 3. Chat events (legacy analytics)
            $this->deleteDirect('chat_events', 'tenant_id', $deleteIds);

            // 4. Chat conversions
            $this->deleteDirect('chat_conversions', 'merchant_id', $deleteIds);

            // 5. Chat session outcomes
            $this->deleteDirect('chat_session_outcomes', 'merchant_id', $deleteIds);

            // 6. Chat daily stats
            $this->deleteDirect('chat_daily_stats', 'merchant_id', $deleteIds);

            // 7. Proactive trigger events
            $this->deleteInChunks('proactive_trigger_events', 'rule_id', $ruleIds);

            // 8. Proactive trigger rules
            $this->deleteDirect('proactive_trigger_rules', 'tenant_id', $deleteIds);

            // 9. Order items
            $this->deleteInChunks('order_items', 'order_id', $orderIds);

            // 10. Orders
            $this->deleteDirect('orders', 'tenant_id', $deleteIds);

            // 11. Product AI index
            $this->deleteInChunks('product_ai_index', 'product_id', $productIds);

            // 12. Product cross-sells
            $this->deleteInChunks('product_cross_sells', 'product_id', $productIds);

            // 13. Product tags junction
            $this->deleteInChunks('product_product_tag', 'product_id', $productIds);

            // 14. Products (force delete, bypass soft-deletes)
            $this->deleteDirect('products', 'tenant_id', $deleteIds);

            // 15. Horoshop products
            $this->deleteDirect('horoshop_products', 'tenant_id', $deleteIds);

            // 16. Rozetka product attribute values
            $this->deleteInChunks('rozetka_product_attribute_values', 'rozetka_product_id', $rozetkaProductIds);

            // 17. Rozetka products
            $this->deleteDirect('rozetka_products', 'tenant_id', $deleteIds);

            // 18. Rozetka category mappings
            $this->deleteDirect('rozetka_category_mappings', 'tenant_id', $deleteIds);

            // 19. Category aliases
            $this->deleteInChunks('category_aliases', 'category_id', $categoryIds);

            // 20. Categories
            $this->deleteDirect('categories', 'tenant_id', $deleteIds);

            // 21. Brands
            $this->deleteDirect('brands', 'tenant_id', $deleteIds);

            // 22. Product synonyms
            $this->deleteDirect('product_synonyms', 'tenant_id', $deleteIds);

            // 23. Prompt presets
            $this->deleteDirect('prompt_presets', 'tenant_id', $deleteIds);

            // 24. Widget settings
            $this->deleteDirect('widget_settings', 'tenant_id', $deleteIds);

            // 25. Store contexts
            $this->deleteDirect('store_contexts', 'tenant_id', $deleteIds);

            // 26. Greetings
            $this->deleteDirect('greetings', 'tenant_id', $deleteIds);

            // 27. Canned responses
            $this->deleteDirect('canned_responses', 'tenant_id', $deleteIds);

            // 28. Payments
            $this->deleteDirect('payments', 'tenant_id', $deleteIds);

            // 29. Subscriptions
            $this->deleteDirect('subscriptions', 'tenant_id', $deleteIds);

            // 30. Sync logs
            $this->deleteDirect('sync_logs', 'tenant_id', $deleteIds);

            // 31. Tenant onboarding progress
            $this->deleteDirect('tenant_onboarding_progress', 'tenant_id', $deleteIds);

            // 32. Users — unlink from tenant, don't delete
            $unlinked = DB::table('users')->whereIn('tenant_id', $deleteIds)->update(['tenant_id' => null]);
            $this->line("  users: unlinked {$unlinked}");

            // 33. Tenants
            $this->deleteDirect('tenants', 'id', $deleteIds);
        });

        $this->info('✅ Cleanup complete!');
    }

    private function deleteDirect(string $table, string $column, array $ids): void
    {
        $count = DB::table($table)->whereIn($column, $ids)->delete();
        $this->line("  {$table}: deleted {$count}");
    }

    private function deleteInChunks(string $table, string $column, array $ids): void
    {
        if (empty($ids)) {
            $this->line("  {$table}: skipped (no parent IDs)");

            return;
        }

        $count = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $count += DB::table($table)->whereIn($column, $chunk)->delete();
        }
        $this->line("  {$table}: deleted {$count}");
    }
}
