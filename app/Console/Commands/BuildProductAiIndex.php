<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use App\Models\Product;
use App\Services\Ai\ProductIndexBuilder;

class BuildProductAiIndex extends Command
{
    protected $signature = 'products:build-ai-index 
        {--limit=0 : Max products to process}
        {--only-missing : Only products without AI index}
        {--batch=50 : Products per batch (lower = more output)}
        {--offset=0 : Skip first N products}
        {--resume : Continue from last saved position}
        {--reset : Reset saved position and start fresh}
        {--timeout=840 : Max runtime in seconds (default 14 min for 15 min cloud limit)}
        {--no-ai : Skip AI calls, only build fallback index}';
    
    protected $aliases = ['build:product-ai-index'];

    protected $description = 'Build or rebuild AI index for products (cloud-safe with resume support)';

    protected const CACHE_KEY = 'build_product_ai_index:last_id';

    public function handle(ProductIndexBuilder $builder): int
    {
        $startTime = microtime(true);
        $timeout = (int) $this->option('timeout');
        $batchSize = max(1, (int) $this->option('batch'));
        $limit = (int) $this->option('limit');
        $offset = (int) $this->option('offset');
        $noAi = $this->option('no-ai');

        // Вивід lock retry в консоль
        $builder->onLockRetry(fn($msg) => $this->warn("[LOCK] {$msg}"));

        // Reset position if requested
        if ($this->option('reset')) {
            Cache::forget(self::CACHE_KEY);
            $this->info('[RESET] Cleared saved position.');
        }

        // Resume from last position
        $lastProcessedId = 0;
        if ($this->option('resume')) {
            $lastProcessedId = (int) Cache::get(self::CACHE_KEY, 0);
            if ($lastProcessedId > 0) {
                $this->info("[RESUME] Continuing from product ID > {$lastProcessedId}");
            }
        }

        // Build query
        $query = Product::query()
            ->where('display_in_showcase', true)
            ->orderBy('id');

        if ($this->option('only-missing')) {
            $query->whereDoesntHave('aiIndex');
        }

        if ($lastProcessedId > 0) {
            $query->where('id', '>', $lastProcessedId);
        }

        if ($offset > 0) {
            $query->skip($offset);
        }

        // Count total
        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('[OK] No products to index.');
            return self::SUCCESS;
        }

        // Apply limit if set
        if ($limit > 0) {
            $total = min($total, $limit);
        }

        $this->info("=================================================");
        $this->info("[START] Building AI index for {$total} products");
        $this->info("  Batch size: {$batchSize} | Timeout: {$timeout}s | No-AI: " . ($noAi ? 'yes' : 'no'));
        $this->info("=================================================");

        $processed = 0;
        $errors = 0;
        $lastId = $lastProcessedId;
        $batchNum = 0;

        // Use cursor for memory efficiency on large datasets
        $productQuery = $limit > 0 ? $query->limit($limit) : $query;

        foreach ($productQuery->lazy($batchSize) as $product) {
            // Check timeout before processing
            $elapsed = microtime(true) - $startTime;
            if ($elapsed >= $timeout) {
                $this->newLine();
                $this->warn("=================================================");
                $this->warn("[TIMEOUT] Reached {$timeout}s limit after {$processed} products.");
                $this->warn("[RESUME] Run with --resume to continue from ID {$lastId}");
                $this->warn("=================================================");
                Cache::put(self::CACHE_KEY, $lastId, now()->addDays(7));
                return self::SUCCESS;
            }

            try {
                if ($noAi) {
                    $builder->buildForProductFallbackOnly($product);
                } else {
                    $builder->buildForProduct($product);
                }
                $lastId = $product->id;
                $processed++;

                // Heartbeat output every batch
                if ($processed % $batchSize === 0) {
                    $batchNum++;
                    $elapsed = round(microtime(true) - $startTime, 1);
                    $remaining = $total - $processed;
                    $rate = $processed > 0 ? round($processed / $elapsed, 2) : 0;
                    $eta = $rate > 0 ? round($remaining / $rate) : '?';
                    
                    $this->info(sprintf(
                        "[BATCH %d] %d/%d done | ID: %d | %.1fs elapsed | %.2f/s | ETA: %ss",
                        $batchNum,
                        $processed,
                        $total,
                        $lastId,
                        $elapsed,
                        $rate,
                        $eta
                    ));

                    // Save progress after each batch
                    Cache::put(self::CACHE_KEY, $lastId, now()->addDays(7));
                }
            } catch (\Throwable $e) {
                $errors++;
                $this->error("[ERROR] Product #{$product->id}: " . $e->getMessage());
                
                // Don't let one error stop everything
                if ($errors > 100) {
                    $this->error("[ABORT] Too many errors ({$errors}), stopping.");
                    break;
                }
            }
        }

        // Final save
        if ($lastId > 0) {
            Cache::put(self::CACHE_KEY, $lastId, now()->addDays(7));
        }

        $elapsed = round(microtime(true) - $startTime, 1);
        $this->newLine();
        $this->info("=================================================");
        $this->info("[DONE] Processed {$processed}/{$total} products in {$elapsed}s");
        if ($errors > 0) {
            $this->warn("[WARN] {$errors} errors encountered");
        }
        $this->info("[LAST ID] {$lastId}");
        $this->info("=================================================");

        return self::SUCCESS;
    }
}
