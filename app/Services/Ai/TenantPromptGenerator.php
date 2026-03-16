<?php

namespace App\Services\Ai;

use App\Models\Product;
use App\Models\PromptPreset;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;

/**
 * Generates tenant-specific system prompts based on store data.
 *
 * Analyzes products, categories, brands, and price ranges to create
 * a custom prompt that fits the tenant's niche. Stored as is_default=true
 * PromptPreset, which BaseAgent uses BEFORE the shared PromptModulesService.
 */
class TenantPromptGenerator
{
    /**
     * Generate and save a default prompt preset for a tenant.
     */
    public function generate(int $tenantId, bool $dryRun = false): array
    {
        $tenant = Tenant::findOrFail($tenantId);
        $analysis = $this->analyzeTenant($tenantId, $tenant);
        $prompt = $this->buildPrompt($analysis);

        if (! $dryRun) {
            $preset = $this->saveAsPreset($tenantId, $tenant, $prompt);

            return [
                'preset_id' => $preset->id,
                'analysis' => $analysis,
                'prompt' => $prompt,
                'prompt_length' => mb_strlen($prompt),
            ];
        }

        return [
            'preset_id' => null,
            'analysis' => $analysis,
            'prompt' => $prompt,
            'prompt_length' => mb_strlen($prompt),
        ];
    }

    /**
     * Analyze tenant's products to determine store niche and characteristics.
     */
    public function analyzeTenant(int $tenantId, ?Tenant $tenant = null): array
    {
        $tenant = $tenant ?? Tenant::findOrFail($tenantId);

        // Top categories by product count
        $categories = Product::where('tenant_id', $tenantId)
            ->whereNotNull('category_path')
            ->where('category_path', '!=', '')
            ->selectRaw('category_path, COUNT(*) as cnt')
            ->groupBy('category_path')
            ->orderByDesc('cnt')
            ->limit(20)
            ->pluck('cnt', 'category_path')
            ->toArray();

        // Top-level category groups
        $topLevelCats = [];
        foreach ($categories as $path => $cnt) {
            $parts = explode(' > ', $path);
            $top = $parts[0] ?? $path;
            $topLevelCats[$top] = ($topLevelCats[$top] ?? 0) + $cnt;
        }
        arsort($topLevelCats);

        // Top brands
        $brands = Product::where('tenant_id', $tenantId)
            ->whereNotNull('brand')
            ->where('brand', '!=', '')
            ->selectRaw('brand, COUNT(*) as cnt')
            ->groupBy('brand')
            ->orderByDesc('cnt')
            ->limit(15)
            ->pluck('cnt', 'brand')
            ->toArray();

        // Price stats for in-stock products
        $priceStats = Product::where('tenant_id', $tenantId)
            ->where('in_stock', true)
            ->selectRaw('MIN(price) as min_price, MAX(price) as max_price, AVG(price) as avg_price, COUNT(*) as in_stock_count')
            ->first();

        // Total products
        $totalProducts = Product::where('tenant_id', $tenantId)->count();

        // Has age categories (children's store detection)
        $hasAgeCategories = Product::where('tenant_id', $tenantId)
            ->where(fn ($q) => $q->where('category_path', 'like', '%МАЛЮКАМ%')
                ->orWhere('category_path', 'like', '%ТОДЛЕРАМ%')
                ->orWhere('category_path', 'like', '%ДОШКІЛЬНЯТАМ%')
                ->orWhere('category_path', 'like', '%ШКОЛЯРАМ%'))
            ->exists();

        // Sample product titles for context
        $sampleTitles = Product::where('tenant_id', $tenantId)
            ->where('in_stock', true)
            ->inRandomOrder()
            ->limit(10)
            ->pluck('title')
            ->toArray();

        return [
            'tenant_id' => $tenantId,
            'store_name' => $tenant->name ?? 'Магазин',
            'domain' => $tenant->domain ?? '',
            'total_products' => $totalProducts,
            'in_stock_count' => (int) ($priceStats->in_stock_count ?? 0),
            'price_min' => (float) ($priceStats->min_price ?? 0),
            'price_max' => (float) ($priceStats->max_price ?? 0),
            'price_avg' => round((float) ($priceStats->avg_price ?? 0)),
            'top_level_categories' => array_slice($topLevelCats, 0, 10, true),
            'categories' => array_slice($categories, 0, 15, true),
            'brands' => array_slice($brands, 0, 10, true),
            'has_age_categories' => $hasAgeCategories,
            'sample_titles' => $sampleTitles,
        ];
    }

    /**
     * Build tenant-specific system prompt from analysis data.
     *
     * Uses the battle-tested PromptModulesService as foundation,
     * and prepends a store profile section with tenant-specific context.
     * This way all the refined rules from PromptModulesService are preserved.
     */
    public function buildPrompt(array $analysis): string
    {
        $storeName = $analysis['store_name'];
        $hasAgeCategories = $analysis['has_age_categories'];
        $modules = app(PromptModulesService::class);

        $sections = [];

        // 1. Store profile (tenant-specific context GPT needs)
        $sections[] = $this->buildStoreProfile($analysis);

        // 2. Core rules from PromptModulesService (battle-tested)
        $sections[] = $modules->getCoreModule('{{shop_phone}}', $storeName);

        // 3. Search module from PromptModulesService (with age awareness)
        $sections[] = $modules->getSearchModule($hasAgeCategories);

        // 4. Follow-up module (always useful as base prompt)
        $sections[] = $modules->getFollowUpModule();

        return implode("\n", array_filter($sections));
    }

    /**
     * Build store profile section from product data.
     * This is the ONLY tenant-specific part; rest comes from PromptModulesService.
     */
    private function buildStoreProfile(array $analysis): string
    {
        $topCats = array_keys($analysis['top_level_categories']);
        $topBrands = array_keys($analysis['brands']);

        $catList = implode(', ', array_slice($topCats, 0, 5));

        $profile = "📋 ПРОФІЛЬ МАГАЗИНУ:\n";
        $profile .= "- Категорії: {$catList}\n";

        if (! empty($topBrands)) {
            $brandList = implode(', ', array_slice($topBrands, 0, 7));
            $profile .= "- Бренди: {$brandList}\n";
        }

        if ($analysis['price_min'] > 0 && $analysis['price_max'] > 0) {
            $profile .= "- Ціни: від {$analysis['price_min']} до {$analysis['price_max']} грн (середня: {$analysis['price_avg']} грн)\n";
        }

        $profile .= "- Товарів в наявності: {$analysis['in_stock_count']}\n";

        // Store-specific search hints from real category names
        if (count($topCats) >= 2) {
            $profile .= "\n🔎 ПРИКЛАДИ ПОШУКУ ДЛЯ ЦЬОГО МАГАЗИНУ:\n";
            $cat1 = mb_strtolower($topCats[0]);
            $cat2 = mb_strtolower($topCats[1]);
            $profile .= "- search_products(\"{$cat1}\") — товари з \"{$topCats[0]}\"\n";
            $profile .= "- search_products(\"{$cat2}\") — товари з \"{$topCats[1]}\"\n";
        }

        return $profile;
    }

    /**
     * Save generated prompt as preset for tenant.
     *
     * If tenant already has a custom is_default=true preset (manually crafted),
     * save the auto-generated one as inactive backup — do NOT overwrite.
     */
    private function saveAsPreset(int $tenantId, Tenant $tenant, string $prompt): PromptPreset
    {
        $existingDefault = PromptPreset::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('is_default', true)
            ->where('is_active', true)
            ->where('slug', '!=', "auto-generated-{$tenantId}")
            ->first();

        $hasCustomDefault = $existingDefault !== null;

        // Create or update the auto-generated preset
        $preset = PromptPreset::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'slug' => "auto-generated-{$tenantId}",
                ],
                [
                    'name' => "Автопромпт: {$tenant->name}",
                    'description' => 'Автоматично згенерований промпт на основі каталогу магазину',
                    'system_prompt' => $prompt,
                    'is_default' => ! $hasCustomDefault,
                    'is_active' => ! $hasCustomDefault,
                    'priority' => 0,
                    'variables' => [],
                    'categories' => [],
                ]
            );

        if ($hasCustomDefault) {
            Log::info('TenantPromptGenerator: custom default exists, saved as inactive backup', [
                'tenant_id' => $tenantId,
                'existing_default_id' => $existingDefault->id,
                'existing_default_name' => $existingDefault->name,
                'backup_preset_id' => $preset->id,
            ]);
        }

        // Clear prompt preset cache
        app(PromptPresetService::class)->clearCache($tenantId);

        Log::info('TenantPromptGenerator: saved preset', [
            'tenant_id' => $tenantId,
            'preset_id' => $preset->id,
            'prompt_length' => mb_strlen($prompt),
        ]);

        return $preset;
    }
}
