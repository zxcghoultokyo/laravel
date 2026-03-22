<?php

namespace App\Console\Commands;

use App\Jobs\GenerateProductEmbeddingsJob;
use App\Models\ProductAiIndex;
use App\Services\Ai\EmbeddingService;
use Illuminate\Console\Command;

class GenerateEmbeddingsCommand extends Command
{
    protected $signature = 'products:generate-embeddings 
        {--batch=50 : Batch size for API calls}
        {--limit=0 : Limit number of products (0 = all)}
        {--tenant= : Tenant ID (omit for all tenants)}
        {--all-tenants : Run for each active tenant separately}
        {--sync : Run synchronously instead of dispatching job}
        {--stats : Show statistics only}';

    protected $description = 'Generate embeddings for products with AI index (for semantic search)';

    public function handle(EmbeddingService $embeddingService): int
    {
        if ($this->option('stats')) {
            return $this->showStats();
        }

        if (! $embeddingService->isAvailable()) {
            $this->error('❌ Embedding service not available. Check OPENAI_API_KEY.');

            return Command::FAILURE;
        }

        // Run for all active tenants
        if ($this->option('all-tenants')) {
            return $this->runForAllTenants($embeddingService);
        }

        $batchSize = (int) $this->option('batch');
        $limit = (int) $this->option('limit');
        $tenantId = $this->option('tenant') ? (int) $this->option('tenant') : null;

        return $this->runForTenant($embeddingService, $batchSize, $limit, $tenantId);
    }

    protected function runForAllTenants(EmbeddingService $embeddingService): int
    {
        $tenants = \App\Models\Tenant::whereHas('products', fn ($q) => $q->where('in_stock', true))
            ->get();

        $this->info("🏢 Running embeddings for {$tenants->count()} tenants");

        foreach ($tenants as $tenant) {
            $this->info("── Tenant #{$tenant->id}: {$tenant->name}");
            $this->runForTenant(
                $embeddingService,
                (int) $this->option('batch'),
                (int) $this->option('limit'),
                $tenant->id
            );
        }

        return Command::SUCCESS;
    }

    protected function runForTenant(EmbeddingService $embeddingService, int $batchSize, int $limit, ?int $tenantId): int
    {
        // Count products needing embeddings
        $query = ProductAiIndex::where(function ($q) {
            $q->whereNull('embedding')->orWhere('embedding', '[]');
        });

        if ($tenantId) {
            $query->whereHas('product', fn ($q) => $q->withoutGlobalScopes()->where('tenant_id', $tenantId));
        }

        $needsEmbedding = $query->count();

        $label = $tenantId ? "tenant #{$tenantId}" : 'all tenants';
        $this->info("🔢 Products needing embeddings ({$label}): {$needsEmbedding}");

        if ($needsEmbedding === 0) {
            $this->info('✅ All products already have embeddings!');

            return Command::SUCCESS;
        }

        // Estimate cost
        $estimatedCost = $needsEmbedding * 0.00002;
        $this->info(sprintf('💰 Estimated cost: ~$%.2f', $estimatedCost));

        if ($this->option('sync')) {
            $this->info('🔄 Running synchronously...');

            $job = new GenerateProductEmbeddingsJob($batchSize, $limit, $tenantId);
            $job->handle($embeddingService);

            $this->info('✅ Done!');
        } else {
            GenerateProductEmbeddingsJob::dispatch($batchSize, $limit, $tenantId);
            $this->info('✅ Job dispatched to queue');
        }

        return Command::SUCCESS;
    }

    protected function showStats(): int
    {
        $total = ProductAiIndex::count();
        $withEmbedding = ProductAiIndex::whereNotNull('embedding')
            ->where('embedding', '!=', '[]')
            ->count();
        $withoutEmbedding = $total - $withEmbedding;

        $this->info('📊 Embedding Statistics:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total AI Index', $total],
                ['With Embedding', $withEmbedding],
                ['Without Embedding', $withoutEmbedding],
                ['Coverage', $total > 0 ? round($withEmbedding / $total * 100, 1).'%' : '0%'],
            ]
        );

        return Command::SUCCESS;
    }
}
