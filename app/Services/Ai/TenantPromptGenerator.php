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
     */
    public function buildPrompt(array $analysis): string
    {
        $storeName = $analysis['store_name'];
        $sections = [];

        // 1. Identity
        $sections[] = "Ти — AI-консультант магазину \"{$storeName}\".";

        // 2. Store description based on categories
        $storeDesc = $this->describeStore($analysis);
        if ($storeDesc) {
            $sections[] = $storeDesc;
        }

        // 3. Core behavior rules
        $sections[] = $this->getCoreRules();

        // 4. Search instructions with store-specific examples
        $sections[] = $this->getSearchInstructions($analysis);

        // 5. Age filtering (only for children's stores)
        if ($analysis['has_age_categories']) {
            $sections[] = $this->getAgeFilteringSection();
        }

        // 6. Seasonality
        $sections[] = $this->getSeasonalitySection();

        // 7. Follow-up instructions
        $sections[] = $this->getFollowUpSection();

        return implode("\n\n", array_filter($sections));
    }

    /**
     * Generate store description from product data.
     */
    private function describeStore(array $analysis): string
    {
        $topCats = array_keys($analysis['top_level_categories']);
        $topBrands = array_keys($analysis['brands']);

        $catList = implode(', ', array_slice($topCats, 0, 5));

        $desc = "📋 ПРОФІЛЬ МАГАЗИНУ:\n";
        $desc .= "- Категорії: {$catList}\n";

        if (! empty($topBrands)) {
            $brandList = implode(', ', array_slice($topBrands, 0, 7));
            $desc .= "- Бренди: {$brandList}\n";
        }

        if ($analysis['price_min'] > 0 && $analysis['price_max'] > 0) {
            $desc .= "- Ціни: від {$analysis['price_min']} до {$analysis['price_max']} грн (середня: {$analysis['price_avg']} грн)\n";
        }

        $desc .= "- Товарів в наявності: {$analysis['in_stock_count']}";

        return $desc;
    }

    /**
     * Core behavior rules (universal for all stores).
     */
    private function getCoreRules(): string
    {
        return <<<'RULES'
🎯 ГОЛОВНІ ПРАВИЛА:
1. ЗАВЖДИ шукай через search_products() перед відповіддю на запит про товари
2. НІКОЛИ не вигадуй — відповідай ТІЛЬКИ з результатів пошуку
3. Показуй МАКСИМУМ 3 товари за раз
4. Мова відповіді = мова запиту (англ→англ, укр→укр)

📝 ФОРМАТ intro:
- Пиши КОНТЕКСТ запиту: "Ось куртки:" / "Ось дешевші варіанти:"
- ❌ ЗАБОРОНЕНО: "Ось що я знайшов" / "Here's what I found"

⛔ ПОСИЛАННЯ — ЗАБОРОНЕНО!
- НЕ генеруй URL/посилання на товари в тексті!
- Посилання додаються АВТОМАТИЧНО через картки
- "Натисніть на картку товару" — якщо просять подробиці

⛔ НЕ УТОЧНЮЙ без потреби!
- Якщо запит зрозумілий — ОДРАЗУ шукай через search_products()
- НЕ питай "який саме розмір/колір/бюджет" якщо клієнт не просив
- НЕ питай про вік, стать, або інші деталі без явного запиту
RULES;
    }

    /**
     * Search instructions with store-specific examples.
     */
    private function getSearchInstructions(array $analysis): string
    {
        $examples = $this->generateSearchExamples($analysis);

        $section = <<<'SEARCH'
🔍 ПОШУК ТОВАРІВ:
- Імпліцитні запити → search_products() з синонімами через OR
- Бренд/модель → шукай ЗА БРЕНДОМ
- Автовиправлення помилок
SEARCH;

        $section .= "\n\n🔄 ЯКЩО ПОШУК НЕ ДАВ РЕЗУЛЬТАТІВ:\n";
        foreach ($examples as $ex) {
            $section .= "- {$ex}\n";
        }
        $section .= '- НЕ КАЖИ "такого немає" після ОДНОГО невдалого пошуку! Спробуй 2-3 варіанти!';

        return $section;
    }

    /**
     * Generate search retry examples based on actual store categories.
     */
    private function generateSearchExamples(array $analysis): array
    {
        $topCats = array_keys($analysis['top_level_categories']);
        $sampleTitles = $analysis['sample_titles'] ?? [];
        $examples = [];

        // Pick real category names for examples
        if (count($topCats) >= 2) {
            $cat1 = mb_strtolower($topCats[0]);
            $cat2 = mb_strtolower($topCats[1] ?? $topCats[0]);
            $examples[] = "Синоніми/ширший запит: \"{$cat1}\" → search_products(\"{$cat1} OR ...\")";
        }

        // Extract a real product keyword from sample titles
        if (! empty($sampleTitles)) {
            $title = $sampleTitles[0];
            $words = explode(' ', $title);
            $keyword = $words[0] ?? 'товар';
            $examples[] = "Спробуй ключове слово з назви: search_products(\"{$keyword}\")";
        }

        $examples[] = 'Розбий складний запит на простіші слова';

        return $examples;
    }

    /**
     * Age filtering section for children's stores.
     */
    private function getAgeFilteringSection(): string
    {
        return <<<'AGE'
👶 ВІКОВА ФІЛЬТРАЦІЯ (КРИТИЧНО!):
Якщо клієнт вказує ВІК дитини — ЗАВЖДИ використай параметр category у search_products!
Спочатку виклич get_categories() щоб побачити доступні категорії.
Вікові категорії: "МАЛЮКАМ 0 – 1", "ТОДЛЕРАМ 1 – 3", "ДОШКІЛЬНЯТАМ 3 – 7", "ШКОЛЯРАМ 7+"
- "для дитини 3 роки" → category="ДОШКІЛЬНЯТАМ 3 – 7"
- "для малюка" / "для немовляти" → category="МАЛЮКАМ 0 – 1"
- "для тодлера" / "1-2 роки" → category="ТОДЛЕРАМ 1 – 3"
БЕЗ фільтра category — пошук поверне товари БУДЬ-ЯКОГО віку, що НЕПРАВИЛЬНО!
AGE;
    }

    /**
     * Seasonality section with current month awareness.
     */
    private function getSeasonalitySection(): string
    {
        $month = (int) date('n');

        if ($month >= 1 && $month <= 2 || $month >= 11) {
            $note = '🎄 Зараз зимовий сезон — різдвяні/новорічні товари актуальні.';
        } elseif ($month >= 3 && $month <= 5) {
            $note = '🌱 Зараз весна — зимові товари НЕ актуальні без запиту.';
        } elseif ($month >= 6 && $month <= 8) {
            $note = '☀️ Зараз літо — зимові товари НЕ актуальні без запиту.';
        } else {
            $note = '🍂 Зараз осінь.';
        }

        return "📅 СЕЗОННІСТЬ:\n{$note}";
    }

    /**
     * Follow-up conversation instructions.
     */
    private function getFollowUpSection(): string
    {
        return <<<'FOLLOWUP'
🔄 FOLLOW-UP:
- "покажи ще" / "ще" → search_products з exclude_shown=true
- "дешевше" → додай price_max / sort_by=price_asc
- "дорожче" → додай price_min / sort_by=price_desc
- "іншого кольору" → search_products з color=...
FOLLOWUP;
    }

    /**
     * Save generated prompt as is_default preset for tenant.
     */
    private function saveAsPreset(int $tenantId, Tenant $tenant, string $prompt): PromptPreset
    {
        // Deactivate existing default preset
        PromptPreset::where('tenant_id', $tenantId)
            ->where('is_default', true)
            ->update(['is_default' => false]);

        // Create or update the generated preset
        $preset = PromptPreset::updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'slug' => "auto-generated-{$tenantId}",
            ],
            [
                'name' => "Автопромпт: {$tenant->name}",
                'description' => 'Автоматично згенерований промпт на основі каталогу магазину',
                'system_prompt' => $prompt,
                'is_default' => true,
                'is_active' => true,
                'priority' => 0,
                'variables' => [],
                'categories' => [],
            ]
        );

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
