<?php

namespace App\Jobs;

use App\Models\StoreContext;
use App\Services\Ai\PromptGeneratorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Analyzes store products and creates/updates StoreContext.
 * 
 * Can be dispatched:
 * - After product sync
 * - On demand from admin panel
 * - Scheduled (daily refresh)
 */
class AnalyzeStoreContextJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        public ?int $widgetSettingsId = null,
        public bool $generatePrompt = true
    ) {}

    public function handle(PromptGeneratorService $generator): void
    {
        Log::info('[AnalyzeStoreContextJob] Starting', [
            'widget_settings_id' => $this->widgetSettingsId,
            'generate_prompt' => $this->generatePrompt,
        ]);

        try {
            // Analyze store and create context
            $context = $generator->analyzeStore($this->widgetSettingsId);

            // Optionally generate prompt
            if ($this->generatePrompt) {
                $generator->generatePrompt($context);
                
                Log::info('[AnalyzeStoreContextJob] Prompt generated', [
                    'store_type' => $context->store_type,
                    'prompt_version' => $context->prompt_version,
                ]);
            }

            Log::info('[AnalyzeStoreContextJob] Complete', [
                'context_id' => $context->id,
                'store_type' => $context->store_type,
                'categories_count' => count($context->primary_categories ?? []),
                'brands_count' => count($context->brands ?? []),
            ]);

        } catch (\Throwable $e) {
            Log::error('[AnalyzeStoreContextJob] Failed', [
                'error' => $e->getMessage(),
                'widget_settings_id' => $this->widgetSettingsId,
            ]);
            
            throw $e;
        }
    }

    public function tags(): array
    {
        return [
            'store-context',
            'widget:' . ($this->widgetSettingsId ?? 'default'),
        ];
    }
}
