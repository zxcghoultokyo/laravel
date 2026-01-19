<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\ProductAiIndex;
use App\Models\SyncLog;
use App\Services\Ai\EmbeddingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job для batch генерації embeddings для товарів.
 * 
 * Генерує embeddings тільки для товарів які мають AI індекс
 * але не мають embedding.
 */
class GenerateProductEmbeddingsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600; // 10 minutes
    public int $tries = 2;

    protected int $batchSize;
    protected int $limit;

    public function __construct(
        int $batchSize = 50,
        int $limit = 0 // 0 = all
    ) {
        $this->batchSize = $batchSize;
        $this->limit = $limit;
    }

    public function handle(EmbeddingService $embeddingService): void
    {
        if (!$embeddingService->isAvailable()) {
            Log::warning('[EmbeddingsJob] Embedding service not available (no API key)');
            return;
        }

        $startTime = microtime(true);
        Log::info('[EmbeddingsJob] Starting batch embedding generation', [
            'batch_size' => $this->batchSize,
            'limit' => $this->limit,
        ]);

        // Create SyncLog entry
        $syncLog = SyncLog::create([
            'sync_type' => SyncLog::TYPE_EMBEDDINGS,
            'status' => SyncLog::STATUS_RUNNING,
            'started_at' => now(),
            'meta' => [
                'batch_size' => $this->batchSize,
                'limit' => $this->limit,
            ],
        ]);

        // Get products with AI index but no embedding
        $query = ProductAiIndex::whereNull('embedding')
            ->orWhere('embedding', '[]')
            ->orderBy('id');

        if ($this->limit > 0) {
            $query->limit($this->limit);
        }

        $total = $query->count();
        $processed = 0;
        $success = 0;
        $failed = 0;

        Log::info("[EmbeddingsJob] Found {$total} products without embeddings");

        $query->with('product')->chunk($this->batchSize, function ($aiIndexes) use ($embeddingService, &$processed, &$success, &$failed) {
            $texts = [];
            $indexMap = [];

            foreach ($aiIndexes as $i => $aiIndex) {
                $product = $aiIndex->product;
                if (!$product) {
                    $failed++;
                    continue;
                }

                $text = $embeddingService->buildProductText([
                    'title' => $product->title,
                    'category_path' => $product->category_path,
                    'brand' => $product->brand,
                    'keywords' => $aiIndex->keywords ?? [],
                    'slang' => $aiIndex->slang ?? [],
                    'description' => $this->extractDescription($product),
                ]);

                if (!empty($text)) {
                    $texts[] = $text;
                    $indexMap[count($texts) - 1] = $aiIndex;
                }
            }

            if (empty($texts)) {
                return true;
            }

            // Batch embed
            $embeddings = $embeddingService->embedBatch($texts);

            // Save embeddings
            foreach ($embeddings as $i => $embedding) {
                $aiIndex = $indexMap[$i] ?? null;
                if (!$aiIndex) continue;

                $processed++;

                if ($embedding && is_array($embedding)) {
                    $aiIndex->embedding = $embedding;
                    $aiIndex->save();
                    $success++;
                } else {
                    $failed++;
                }
            }

            Log::info("[EmbeddingsJob] Batch processed", [
                'processed' => $processed,
                'success' => $success,
                'failed' => $failed,
            ]);

            // Small delay to avoid rate limits
            usleep(100000); // 100ms

            return true;
        });

        $elapsed = round(microtime(true) - $startTime, 2);

        Log::info('[EmbeddingsJob] Completed', [
            'total' => $total,
            'processed' => $processed,
            'success' => $success,
            'failed' => $failed,
            'elapsed_seconds' => $elapsed,
        ]);

        // Update SyncLog
        $syncLog->update([
            'status' => SyncLog::STATUS_COMPLETED,
            'completed_at' => now(),
            'items_synced' => $success,
            'meta' => array_merge($syncLog->meta ?? [], [
                'total' => $total,
                'processed' => $processed,
                'success' => $success,
                'failed' => $failed,
                'elapsed_seconds' => $elapsed,
            ]),
        ]);
    }

    protected function extractDescription(Product $product): ?string
    {
        $raw = $product->raw ?? [];

        $description = $raw['description']['ua']
            ?? $raw['description']['ru']
            ?? $raw['short_description']['ua']
            ?? $raw['short_description']['ru']
            ?? null;

        if (is_string($description)) {
            return strip_tags($description);
        }

        return null;
    }
}
