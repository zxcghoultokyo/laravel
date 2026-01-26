<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\SyncLog;
use App\Services\Catalog\ColorDetectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job to automatically detect and update product colors
 * 
 * Uses ColorThief library for image analysis
 * Priority: description keywords > image analysis
 * 
 * Resource usage: ~0.5-1s per image (cached for 24h)
 * Recommended batch size: 50-100 products
 */
class DetectProductColorsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600; // 10 minutes max
    public int $tries = 1;

    private int $batchSize;
    private ?int $tenantId;
    private bool $analyzeImages;
    private bool $dryRun;

    /**
     * The queue this job should be sent to.
     */
    public function onQueue(): string
    {
        return 'default';
    }

    /**
     * Create a new job instance.
     *
     * @param int $batchSize Number of products to process per run
     * @param int|null $tenantId Specific tenant or null for all active tenants
     * @param bool $analyzeImages Whether to analyze images (slower but more accurate)
     * @param bool $dryRun If true, only detect but don't update
     */
    public function __construct(
        int $batchSize = 50,
        ?int $tenantId = null,
        bool $analyzeImages = true,
        bool $dryRun = false
    ) {
        $this->batchSize = $batchSize;
        $this->tenantId = $tenantId;
        $this->analyzeImages = $analyzeImages;
        $this->dryRun = $dryRun;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $startTime = microtime(true);
        
        // Create sync log
        $syncLog = SyncLog::create([
            'sync_type' => SyncLog::TYPE_COLOR_DETECTION ?? 'color_detection',
            'status' => SyncLog::STATUS_RUNNING,
            'started_at' => now(),
            'notes' => "Batch: {$this->batchSize}, Images: " . ($this->analyzeImages ? 'yes' : 'no'),
        ]);

        try {
            $colorService = new ColorDetectionService();
            
            // Get tenants to process
            $tenantIds = $this->tenantId 
                ? [$this->tenantId]
                : \App\Models\Tenant::where('status', 'active')->pluck('id')->toArray();
            
            $totalProcessed = 0;
            $totalDetected = 0;
            $totalUpdated = 0;
            $errors = [];

            foreach ($tenantIds as $tid) {
                $result = $this->processTenantsProducts($tid, $colorService);
                $totalProcessed += $result['processed'];
                $totalDetected += $result['detected'];
                $totalUpdated += $result['updated'];
                if (!empty($result['errors'])) {
                    $errors = array_merge($errors, $result['errors']);
                }
            }

            $duration = round(microtime(true) - $startTime, 2);
            
            $syncLog->update([
                'status' => SyncLog::STATUS_COMPLETED,
                'finished_at' => now(),
                'duration_seconds' => $duration,
                'items_processed' => $totalProcessed,
                'items_created' => $totalDetected,
                'items_updated' => $totalUpdated,
                'notes' => sprintf(
                    "Processed: %d, Detected: %d, Updated: %d, Duration: %ds%s",
                    $totalProcessed, $totalDetected, $totalUpdated, $duration,
                    $this->dryRun ? ' (DRY RUN)' : ''
                ),
                'error_message' => !empty($errors) ? implode('; ', array_slice($errors, 0, 5)) : null,
            ]);

            Log::info('DetectProductColorsJob completed', [
                'processed' => $totalProcessed,
                'detected' => $totalDetected,
                'updated' => $totalUpdated,
                'duration' => $duration,
                'dry_run' => $this->dryRun,
            ]);

        } catch (\Throwable $e) {
            $syncLog->update([
                'status' => SyncLog::STATUS_FAILED,
                'finished_at' => now(),
                'duration_seconds' => round(microtime(true) - $startTime, 2),
                'error_message' => $e->getMessage(),
            ]);

            Log::error('DetectProductColorsJob failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Process products for a specific tenant
     */
    private function processTenantsProducts(int $tenantId, ColorDetectionService $colorService): array
    {
        $result = [
            'processed' => 0,
            'detected' => 0,
            'updated' => 0,
            'errors' => [],
        ];

        // Get products without color using DB facade (bypass TenantScope)
        $products = DB::table('products')
            ->select(['id', 'article', 'title', 'color', 'images', 'raw'])
            ->where('tenant_id', $tenantId)
            ->where('in_stock', true)
            ->where(function($q) {
                $q->whereNull('color')
                    ->orWhere('color', '')
                    ->orWhere('color', 'null');
            })
            ->limit($this->batchSize)
            ->get();

        foreach ($products as $product) {
            $result['processed']++;
            
            try {
                // Parse raw JSON
                $raw = is_string($product->raw) 
                    ? (json_decode($product->raw, true) ?: []) 
                    : [];

                // Get image URL from images column first
                $imageUrl = null;
                if ($this->analyzeImages) {
                    $imagesCol = $product->images ?? '';
                    if (is_string($imagesCol) && !empty($imagesCol)) {
                        $imagesArr = json_decode($imagesCol, true);
                        if (is_array($imagesArr) && !empty($imagesArr[0])) {
                            $imageUrl = $imagesArr[0];
                        }
                    }
                    
                    // Fallback to raw
                    if (!$imageUrl) {
                        $imageUrl = $raw['pictures'][0]['url'] 
                            ?? $raw['images'][0]['url'] 
                            ?? $raw['image'] 
                            ?? null;
                    }
                }

                // Get description text
                $description = $raw['description'] ?? '';
                if (is_array($description)) {
                    $description = implode(' ', array_filter($description, 'is_string'));
                }

                // Detect color
                $detectedColor = null;
                
                // Priority 1: Title keywords (highest priority - most accurate)
                $title = $product->title ?? '';
                if (!empty($title)) {
                    $detectedColor = $colorService->extractColorFromText($title);
                }
                
                // Priority 2: Raw color attribute (from Horoshop)
                if (!$detectedColor) {
                    $rawColor = $raw['color'] ?? $raw['Колір'] ?? $raw['kolir'] ?? null;
                    if (!empty($rawColor) && is_string($rawColor)) {
                        $detectedColor = $colorService->extractColorFromText($rawColor);
                        // If raw color doesn't match keywords, use it directly
                        if (!$detectedColor && !in_array(strtolower($rawColor), ['null', '-', 'n/a', ''])) {
                            $detectedColor = $rawColor;
                        }
                    }
                }
                
                // Priority 3: Description keywords
                if (!$detectedColor && is_string($description) && !empty($description)) {
                    $detectedColor = $colorService->extractColorFromText($description);
                }
                
                // Priority 4: Attributes text
                if (!$detectedColor) {
                    $attributes = $raw['attributes'] ?? $raw['attrs'] ?? [];
                    if (is_array($attributes)) {
                        foreach ($attributes as $attr) {
                            $attrName = is_array($attr) ? ($attr['name'] ?? '') : '';
                            $attrValue = is_array($attr) ? ($attr['value'] ?? '') : (is_string($attr) ? $attr : '');
                            if (stripos($attrName, 'колір') !== false || stripos($attrName, 'color') !== false) {
                                if (!empty($attrValue)) {
                                    $detectedColor = $colorService->extractColorFromText($attrValue);
                                    if (!$detectedColor) {
                                        $detectedColor = $attrValue; // Use raw attribute value
                                    }
                                    break;
                                }
                            }
                        }
                    }
                }
                
                // Priority 5: Image analysis (only if no color from text)
                if (!$detectedColor && $imageUrl) {
                    try {
                        $detectedColor = $colorService->analyzeImage($imageUrl);
                    } catch (\Throwable $imgErr) {
                        // Image analysis failed, continue without it
                        Log::debug('Color detection image error', [
                            'product_id' => $product->id,
                            'error' => $imgErr->getMessage(),
                        ]);
                    }
                }

                if ($detectedColor) {
                    $result['detected']++;
                    
                    if (!$this->dryRun) {
                        DB::table('products')
                            ->where('id', $product->id)
                            ->update(['color' => $detectedColor]);
                        $result['updated']++;
                    }
                }

            } catch (\Throwable $e) {
                $result['errors'][] = "Product {$product->id}: {$e->getMessage()}";
                Log::warning('Color detection error for product', [
                    'product_id' => $product->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }
}
