<?php

namespace App\Console\Commands;

use App\Jobs\DetectProductColorsJob;
use App\Models\SyncLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DetectProductColorsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'colors:detect 
                            {--limit=100 : Maximum products to process}
                            {--tenant= : Tenant ID (all tenants if not specified)}
                            {--skip-images : Skip image analysis, only use keywords}
                            {--dry-run : Preview without saving}
                            {--sync : Run synchronously instead of dispatching job}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Auto-detect colors for products without color attribute';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $tenantId = $this->option('tenant') ? (int) $this->option('tenant') : null;
        $analyzeImages = !$this->option('skip-images');
        $dryRun = $this->option('dry-run');
        $sync = $this->option('sync');

        $this->info("🎨 Color Detection");
        $this->line("  Limit: {$limit}");
        $this->line("  Tenant: " . ($tenantId ?? 'all'));
        $this->line("  Analyze images: " . ($analyzeImages ? 'yes' : 'no'));
        $this->line("  Dry run: " . ($dryRun ? 'yes' : 'no'));
        $this->line("  Mode: " . ($sync ? 'synchronous' : 'queued'));

        if ($sync) {
            // Run synchronously - same logic as the job
            return $this->runSync($limit, $tenantId, $analyzeImages, $dryRun);
        }

        // Dispatch job to queue
        DetectProductColorsJob::dispatch($limit, $tenantId, $analyzeImages, $dryRun);
        $this->info("✅ Job dispatched to queue");

        return self::SUCCESS;
    }

    /**
     * Run color detection synchronously
     */
    private function runSync(int $limit, ?int $tenantId, bool $analyzeImages, bool $dryRun): int
    {
        $colorService = app(\App\Services\Catalog\ColorDetectionService::class);

        // Create sync log
        $syncLog = null;
        if (!$dryRun) {
            $syncLog = SyncLog::create([
                'sync_type' => SyncLog::TYPE_COLOR_DETECTION,
                'status' => SyncLog::STATUS_RUNNING,
                'started_at' => now(),
                'meta' => [
                    'limit' => $limit,
                    'tenant_id' => $tenantId,
                    'analyze_images' => $analyzeImages,
                    'triggered_by' => 'command',
                ],
            ]);
        }

        try {
            // Get products without color
            $query = DB::table('products')
                ->where('in_stock', true)
                ->where(function ($q) {
                    $q->whereNull('color')
                        ->orWhere('color', '');
                })
                ->select(['id', 'article', 'title', 'description', 'images', 'raw']);

            if ($tenantId) {
                $query->where('tenant_id', $tenantId);
            }

            $products = $query->limit($limit)->get();

            $this->info("Found {$products->count()} products without color");

            $stats = [
                'processed' => 0,
                'detected' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => 0,
            ];

            $bar = $this->output->createProgressBar($products->count());
            $bar->start();

            foreach ($products as $product) {
                $stats['processed']++;

                try {
                    // Parse raw JSON
                    $raw = is_string($product->raw) ? json_decode($product->raw, true) : $product->raw;
                    $images = is_string($product->images) ? json_decode($product->images, true) : $product->images;

                    // Build description text
                    $description = $product->description;
                    if (is_array($description)) {
                        $description = implode(' ', $description);
                    }
                    if (empty($description) && !empty($raw['description'])) {
                        $description = is_array($raw['description']) 
                            ? implode(' ', $raw['description']) 
                            : $raw['description'];
                    }

                    // Extract first image URL
                    $imageUrl = null;
                    if ($analyzeImages) {
                        if (!empty($raw['pictures'][0]['url'])) {
                            $imageUrl = $raw['pictures'][0]['url'];
                        } elseif (!empty($raw['images'][0]['url'])) {
                            $imageUrl = $raw['images'][0]['url'];
                        } elseif (!empty($images[0])) {
                            $imageUrl = is_array($images[0]) ? ($images[0]['url'] ?? null) : $images[0];
                        } elseif (!empty($raw['image'])) {
                            $imageUrl = $raw['image'];
                        }
                    }

                    // Detect color
                    $detectedColor = $colorService->detectColor(
                        $product->title ?? '',
                        $description ?? '',
                        $imageUrl
                    );

                    if ($detectedColor) {
                        $stats['detected']++;

                        if (!$dryRun) {
                            DB::table('products')
                                ->where('id', $product->id)
                                ->update(['color' => $detectedColor, 'updated_at' => now()]);
                            $stats['updated']++;
                        }

                        if ($this->getOutput()->isVerbose()) {
                            $this->newLine();
                            $this->line("  ✅ {$product->article}: {$detectedColor}");
                        }
                    } else {
                        $stats['skipped']++;
                    }
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    if ($this->getOutput()->isVerbose()) {
                        $this->newLine();
                        $this->error("  ❌ {$product->article}: {$e->getMessage()}");
                    }
                }

                $bar->advance();
            }

            $bar->finish();
            $this->newLine(2);

            // Update sync log
            if ($syncLog) {
                $syncLog->update([
                    'status' => SyncLog::STATUS_COMPLETED,
                    'completed_at' => now(),
                    'items_synced' => $stats['updated'],
                    'meta' => array_merge($syncLog->meta ?? [], ['stats' => $stats]),
                ]);
            }

            // Summary
            $this->table(
                ['Metric', 'Count'],
                [
                    ['Processed', $stats['processed']],
                    ['Detected', $stats['detected']],
                    ['Updated', $stats['updated']],
                    ['Skipped (no color found)', $stats['skipped']],
                    ['Errors', $stats['errors']],
                ]
            );

            return self::SUCCESS;

        } catch (\Throwable $e) {
            if ($syncLog) {
                $syncLog->update([
                    'status' => SyncLog::STATUS_FAILED,
                    'completed_at' => now(),
                    'error_message' => $e->getMessage(),
                ]);
            }

            $this->error("❌ Error: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
}
