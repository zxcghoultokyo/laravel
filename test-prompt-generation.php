<?php

/**
 * Test Store Context and Prompt Generation
 * 
 * Usage: php test-prompt-generation.php
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Ai\PromptGeneratorService;
use App\Models\StoreContext;

echo "╔═══════════════════════════════════════════════════════════╗\n";
echo "║     Store Context & Prompt Generation Test               ║\n";
echo "╚═══════════════════════════════════════════════════════════╝\n\n";

$generator = app(PromptGeneratorService::class);

// 1. Analyze store
echo "📊 Analyzing store...\n";
$context = $generator->analyzeStore(null);

echo "✅ Store Type: " . $context->getStoreTypeLabel() . " ({$context->store_type})\n";
echo "📦 Products: " . count($context->primary_categories ?? []) . " categories\n";
echo "🏷️  Brands: " . count($context->brands ?? []) . " detected\n";
echo "💰 Price Segments: \n";
foreach (['budget', 'mid', 'premium'] as $segment) {
    $label = $context->getPriceSegmentLabel($segment);
    if ($label) {
        echo "   - " . ucfirst($segment) . ": {$label}\n";
    }
}
echo "\n";

// 2. Generate prompt
echo "🤖 Generating AI prompt...\n";
$prompt = $generator->generatePrompt($context);
$context->refresh();

echo "✅ Prompt version: {$context->prompt_version}\n";
echo "📝 Prompt preview:\n";
echo str_repeat("─", 60) . "\n";
echo substr($prompt, 0, 500) . "...\n";
echo str_repeat("─", 60) . "\n\n";

// 3. Create PromptPreset
echo "💾 Creating PromptPreset...\n";
$preset = $generator->createPresetFromContext($context, "Test Auto-Generated");

echo "✅ Preset created: {$preset->name} (ID: {$preset->id})\n";
echo "   Slug: {$preset->slug}\n";
echo "   Default: " . ($preset->is_default ? 'Yes' : 'No') . "\n";
echo "   Active: " . ($preset->is_active ? 'Yes' : 'No') . "\n\n";

// 4. Show full stats
echo "📈 Full Stats:\n";
echo "   Categories: " . implode(', ', array_slice($context->primary_categories ?? [], 0, 5)) . "\n";
echo "   Expertise: " . implode(', ', $context->expertise_areas ?? []) . "\n";
echo "   Last analyzed: " . $context->last_analyzed_at->diffForHumans() . "\n";
echo "   Needs refresh: " . ($context->needsRefresh() ? '⚠️  Yes' : '✅ No') . "\n\n";

echo "✨ Done!\n";
