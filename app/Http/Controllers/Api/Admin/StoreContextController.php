<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\AnalyzeStoreContextJob;
use App\Models\StoreContext;
use App\Services\Ai\PromptGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manage store context and auto-generate prompts.
 */
class StoreContextController extends Controller
{
    public function __construct(
        private PromptGeneratorService $generator
    ) {}

    /**
     * Analyze store and create/update context.
     * 
     * POST /api/admin/store-context/analyze
     */
    public function analyze(Request $request): JsonResponse
    {
        $widgetSettingsId = $request->input('widget_settings_id');
        $generatePrompt = $request->boolean('generate_prompt', true);
        $async = $request->boolean('async', false);

        if ($async) {
            AnalyzeStoreContextJob::dispatch($widgetSettingsId, $generatePrompt);
            
            return response()->json([
                'status' => 'queued',
                'message' => 'Store analysis job queued',
            ]);
        }

        $context = $this->generator->analyzeStore($widgetSettingsId);
        
        if ($generatePrompt) {
            $this->generator->generatePrompt($context);
            $context->refresh();
        }

        return response()->json([
            'status' => 'success',
            'context' => $this->formatContext($context),
        ]);
    }

    /**
     * Get current store context.
     * 
     * GET /api/admin/store-context
     */
    public function show(Request $request): JsonResponse
    {
        $widgetSettingsId = $request->input('widget_settings_id');

        $context = StoreContext::where('widget_settings_id', $widgetSettingsId)
            ->orWhereNull('widget_settings_id')
            ->orderByDesc('updated_at')
            ->first();

        if (!$context) {
            return response()->json([
                'status' => 'not_found',
                'message' => 'No store context found. Run analyze first.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'context' => $this->formatContext($context),
        ]);
    }

    /**
     * Generate prompt from existing context.
     * 
     * POST /api/admin/store-context/generate-prompt
     * 
     * @param bool use_ai - Use GPT to generate intelligent prompt (slower, better for unusual niches)
     */
    public function generatePrompt(Request $request): JsonResponse
    {
        $contextId = $request->input('context_id');
        $useAi = $request->boolean('use_ai', false);
        
        $context = $contextId 
            ? StoreContext::findOrFail($contextId)
            : StoreContext::latest()->firstOrFail();

        $prompt = $this->generator->generatePrompt($context, $useAi);
        $context->refresh();

        return response()->json([
            'status' => 'success',
            'prompt' => $prompt,
            'prompt_version' => $context->prompt_version,
            'generated_with' => $useAi ? 'ai' : 'template',
        ]);
    }

    /**
     * Create PromptPreset from store context.
     * 
     * POST /api/admin/store-context/create-preset
     */
    public function createPreset(Request $request): JsonResponse
    {
        $contextId = $request->input('context_id');
        $name = $request->input('name');

        $context = $contextId 
            ? StoreContext::findOrFail($contextId)
            : StoreContext::latest()->firstOrFail();

        $preset = $this->generator->createPresetFromContext($context, $name);

        return response()->json([
            'status' => 'success',
            'preset' => [
                'id' => $preset->id,
                'name' => $preset->name,
                'slug' => $preset->slug,
                'is_default' => $preset->is_default,
            ],
        ]);
    }

    /**
     * Format context for API response.
     */
    private function formatContext(StoreContext $context): array
    {
        return [
            'id' => $context->id,
            'widget_settings_id' => $context->widget_settings_id,
            'store_type' => $context->store_type,
            'store_type_label' => $context->getStoreTypeLabel(),
            'primary_categories' => $context->primary_categories,
            'brands' => $context->brands,
            'price_segments' => $context->price_segments,
            'catalog_size' => $context->catalog_size,
            'expertise_areas' => $context->expertise_areas,
            'delivery_info' => $context->delivery_info,
            'return_policy' => $context->return_policy,
            'generated_prompt' => $context->generated_prompt,
            'prompt_version' => $context->prompt_version,
            'last_analyzed_at' => $context->last_analyzed_at?->toISOString(),
            'needs_refresh' => $context->needsRefresh(),
            'updated_at' => $context->updated_at->toISOString(),
        ];
    }
}
