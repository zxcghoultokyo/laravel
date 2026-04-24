<?php

namespace App\Console\Commands;

use App\Jobs\IndexProductsToMeiliJob;
use App\Models\Product;
use App\Scopes\TenantScope;
use Illuminate\Console\Command;

class MeiliReindexProducts extends Command
{
    protected $signature = 'meili:reindex-products
        {--tenant= : Reindex only for specific tenant_id}
        {--chunk=500 : Products per chunk}';

    protected $description = 'Dispatch products reindex jobs to Meilisearch (queue: meili)';

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $tenantId = $this->option('tenant') !== null ? (int) $this->option('tenant') : null;

        $query = Product::query()->withoutGlobalScope(TenantScope::class);
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }
        $total = $query->count();

        if ($total === 0) {
            $this->info('No products found. Nothing to index.');

            return self::SUCCESS;
        }

        IndexProductsToMeiliJob::dispatch($tenantId, $chunk)->onQueue('meili');

        $scope = $tenantId !== null ? "tenant #{$tenantId}" : 'all tenants';
        $this->info("Dispatched reindex job for {$total} product(s) ({$scope}). Chunk={$chunk}.");

        return self::SUCCESS;
    }
}
