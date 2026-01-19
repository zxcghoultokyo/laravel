<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FixNullTenantIds extends Command
{
    protected $signature = 'products:fix-tenant-ids 
                            {--tenant= : Assign all NULL tenant products to this tenant ID (default: 1)}
                            {--dry-run : Show what would be updated without making changes}';

    protected $description = 'Fix products with NULL tenant_id by assigning them to a default tenant';

    public function handle(): int
    {
        $tenantId = (int) $this->option('tenant') ?: 1;
        $dryRun = $this->option('dry-run');

        // Verify tenant exists
        $tenant = Tenant::find($tenantId);
        if (!$tenant) {
            $this->error("Tenant with ID {$tenantId} not found!");
            return 1;
        }

        // Count products with NULL tenant_id
        $nullCount = Product::withoutGlobalScope(TenantScope::class)
            ->whereNull('tenant_id')
            ->count();

        if ($nullCount === 0) {
            $this->info('✅ No products with NULL tenant_id found. Database is clean!');
            return 0;
        }

        $this->info("Found {$nullCount} products with NULL tenant_id");
        $this->info("Will assign to tenant: {$tenant->name} (ID: {$tenantId})");

        if ($dryRun) {
            $this->warn('DRY RUN - no changes will be made');
            
            // Show sample of affected products
            $samples = Product::withoutGlobalScope(TenantScope::class)
                ->whereNull('tenant_id')
                ->limit(10)
                ->get(['id', 'article', 'title']);
            
            $this->table(
                ['ID', 'Article', 'Title'],
                $samples->map(fn($p) => [$p->id, $p->article, mb_substr($p->title, 0, 50)])
            );
            
            return 0;
        }

        if (!$this->confirm("Update {$nullCount} products to tenant_id = {$tenantId}?")) {
            $this->info('Cancelled.');
            return 0;
        }

        // Update in chunks to avoid memory issues
        $this->info('Updating products...');
        $bar = $this->output->createProgressBar($nullCount);
        $bar->start();

        $updated = 0;
        Product::withoutGlobalScope(TenantScope::class)
            ->whereNull('tenant_id')
            ->chunkById(500, function ($products) use ($tenantId, &$updated, $bar) {
                foreach ($products as $product) {
                    $product->tenant_id = $tenantId;
                    $product->save();
                    $updated++;
                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine(2);

        Log::info('FixNullTenantIds completed', [
            'updated_count' => $updated,
            'tenant_id' => $tenantId,
        ]);

        $this->info("✅ Updated {$updated} products with tenant_id = {$tenantId}");

        // Verify
        $remainingNull = Product::withoutGlobalScope(TenantScope::class)
            ->whereNull('tenant_id')
            ->count();

        if ($remainingNull > 0) {
            $this->warn("⚠️ Still {$remainingNull} products with NULL tenant_id (might be race condition)");
        } else {
            $this->info('✅ All products now have tenant_id assigned!');
        }

        return 0;
    }
}
