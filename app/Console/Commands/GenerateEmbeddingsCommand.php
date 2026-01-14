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
        {--sync : Run synchronously instead of dispatching job}
        {--stats : Show statistics only}';

    protected $description = 'Generate embeddings for products with AI index (for semantic search)';

    public function handle(EmbeddingService $embeddingService): int
    {
        if ($this->option('stats')) {
            return $this->showStats();
        }

        if (!$embeddingService->isAvailable()) {
            $this->error('❌ Embedding service not available. Check OPENAI_API_KEY.');
            return Command::FAILURE;
        }

        $batchSize = (int) $this->option('batch');
        $limit = (int) $this->option('limit');

        // Count products needing embeddings
        $needsEmbedding = ProductAiIndex::whereNull('embedding')
            ->orWhere('embedding', '[]')
            ->count();

        $this->info("🔢 Products needing embeddings: {$needsEmbedding}");

        if ($needsEmbedding === 0) {
            $this->info('✅ All products already have embeddings!');
            return Command::SUCCESS;
        }

        // Estimate cost
        $estimatedCost = $needsEmbedding * 0.00002; // ~$0.02 per 1000 tokens, ~1000 tokens per product
        $this->info(sprintf("💰 Estimated cost: ~$%.2f", $estimatedCost));

        if ($this->option('sync')) {
            $this->info('🔄 Running synchronously...');
            
            $job = new GenerateProductEmbeddingsJob($batchSize, $limit);
            $job->handle($embeddingService);
            
            $this->info('✅ Done!');
        } else {
            GenerateProductEmbeddingsJob::dispatch($batchSize, $limit);
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
                ['Coverage', $total > 0 ? round($withEmbedding / $total * 100, 1) . '%' : '0%'],
            ]
        );

        return Command::SUCCESS;
    }
}
