<?php

namespace App\Services\Agent\Handlers;

use App\DTO\AgentResponseDTO;
use App\Services\Ai\AiRouter;
use App\Services\Search\ColorService;
use Illuminate\Support\Facades\Log;

/**
 * Service for building product response narratives.
 */
class NarrativeBuilder
{
    public function __construct(
        private AiRouter $aiRouter,
        private ColorService $colorService,
    ) {}

    /**
     * Build narrative message for product search results.
     */
    public function buildProductNarrative(
        array $products,
        string $originalMessage,
        array $filters,
        array $sessionContext,
        ?string $sessionId
    ): string {
        $productsForPrompt = array_slice($products, 0, 4);

        if (empty($productsForPrompt)) {
            return "Ось варіанти";
        }

        $flow = $this->detectProductFlow($originalMessage);

        if ($flow === 'comparison') {
            return $this->buildComparisonNarrative($productsForPrompt, $originalMessage);
        }

        // NEW: Build individual product cards with descriptions
        // Returns short intro only, cards have their own descriptions
        return $this->buildIntroForCards($productsForPrompt, $filters, $originalMessage);
    }

    /**
     * Build product cards with individual descriptions.
     * Each card has: product data + short description
     * @return array<int, array{description: string, product: array}>
     */
    public function buildProductCards(
        array $products,
        string $originalMessage,
        array $filters
    ): array {
        $cards = [];
        $count = count($products);
        
        foreach ($products as $i => $product) {
            $description = $this->buildProductCardDescription($product, $i, $count, $filters, $originalMessage);
            $cards[] = [
                'description' => $description,
                'product' => $product,
            ];
        }
        
        return $cards;
    }

    /**
     * Build short intro text for card-based display.
     */
    protected function buildIntroForCards(array $products, array $filters, string $originalMessage): string
    {
        $count = count($products);
        $m = mb_strtolower($originalMessage);
        
        // Help/recommendation requests - short acknowledgment
        $helpKeywords = ['допомож', 'підбер', 'порад', 'рекоменд', 'підкаж', 'потрібн', 'яку обрати', 'який обрати', 'який краще', 'яка краще'];
        foreach ($helpKeywords as $kw) {
            if (str_contains($m, $kw)) {
                if ($count === 1) {
                    return "Рекомендую такий варіант:";
                }
                return "Ось мої рекомендації:";
            }
        }
        
        // Budget search
        if (!empty($filters['budget_max'])) {
            return "Варіанти до {$filters['budget_max']} ₴:";
        }
        
        // Color search  
        if (!empty($filters['color'])) {
            $color = $filters['color'];
            return "Варіанти в кольорі «{$color}»:";
        }
        
        // Default
        if ($count === 1) {
            return "Ось що знайшов:";
        }
        return "Ось варіанти:";
    }

    /**
     * Build description for individual product card.
     * Short, focused on key differentiator.
     */
    protected function buildProductCardDescription(
        array $product,
        int $index,
        int $totalCount,
        array $filters,
        string $originalMessage
    ): string {
        $title = $product['title'] ?? 'Товар';
        $price = isset($product['price']) ? round($product['price']) : null;
        $brand = $product['brand'] ?? null;
        $inStock = $product['in_stock'] ?? false;
        
        $parts = [];
        
        // Position context for comparisons (if multiple products)
        if ($totalCount > 1) {
            if ($index === 0) {
                // Cheapest or most popular
                $parts[] = $this->getFirstProductHighlight($product, $totalCount);
            } elseif ($index === $totalCount - 1 && $price) {
                // Premium option
                $parts[] = "💎 Преміум варіант";
            } else {
                // Middle option
                $parts[] = "⚖️ Збалансований вибір";
            }
        }
        
        // Brand highlight if notable
        if ($brand && $this->isNotableBrand($brand)) {
            $parts[] = "Бренд {$brand}";
        }
        
        // Stock status if limited
        if (!$inStock) {
            $parts[] = "⏳ Під замовлення";
        }
        
        // Extract one key feature
        $feature = $this->extractOneKeyFeature($product);
        if ($feature) {
            $parts[] = $feature;
        }
        
        // Build final description
        if (empty($parts)) {
            return "";
        }
        
        return implode(' • ', array_slice($parts, 0, 2));
    }

    /**
     * Get highlight for first product (usually best value).
     */
    protected function getFirstProductHighlight(array $product, int $totalCount): string
    {
        $price = $product['price'] ?? 0;
        $popularity = $product['popularity'] ?? 0;
        
        if ($popularity > 50) {
            return "⭐ Популярний вибір";
        }
        
        return "💰 Найдоступніший варіант";
    }

    /**
     * Check if brand is notable enough to highlight.
     */
    protected function isNotableBrand(?string $brand): bool
    {
        if (!$brand) return false;
        
        $notableBrands = [
            'атака', 'velmet', 'uatac', 'м-тас', 'm-tac', 'tasmanian tiger',
            'direct action', 'helikon', 'firstspear', '5.11', 'condor',
            'mechanix', 'magpul', 'crye', 'ferro concepts',
        ];
        
        $brandLower = mb_strtolower($brand);
        foreach ($notableBrands as $notable) {
            if (str_contains($brandLower, $notable)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Extract one key feature from product.
     */
    protected function extractOneKeyFeature(array $product): ?string
    {
        // Try attributes
        $attrs = $product['attrs'] ?? $product['attributes'] ?? [];
        if (is_array($attrs)) {
            // Priority attributes
            $priorityKeys = ['матеріал', 'material', 'вага', 'weight', 'розмір', 'size', 'клас захисту'];
            foreach ($priorityKeys as $key) {
                foreach ($attrs as $k => $v) {
                    if (is_string($v) && mb_strlen($v) > 2 && mb_strlen($v) < 50) {
                        if (str_contains(mb_strtolower($k), $key)) {
                            return ucfirst($k) . ': ' . $v;
                        }
                    }
                }
            }
        }
        
        return null;
    }

    /**
     * Detect contextual intro message based on user's request.
     */
    protected function detectContextualIntro(string $message, array $filters, int $productsCount): string
    {
        $m = mb_strtolower($message);
        
        // Help/recommendation requests
        $helpKeywords = ['допомож', 'підбер', 'порад', 'рекоменд', 'підкаж', 'потрібн'];
        foreach ($helpKeywords as $kw) {
            if (str_contains($m, $kw)) {
                $intros = [
                    "Ось найкращі варіанти для вас:\n\n",
                    "Рекомендую такі варіанти:\n\n",
                    "Ось що можу запропонувати:\n\n",
                ];
                return $intros[array_rand($intros)];
            }
        }
        
        // Budget-specific search
        if (!empty($filters['budget_max'])) {
            $budget = $filters['budget_max'];
            return "Варіанти до {$budget} ₴:\n\n";
        }
        
        // Color-specific search
        if (!empty($filters['color'])) {
            return ""; // Let products speak for themselves
        }
        
        // Best sellers / popular items
        $popularKeywords = ['популярн', 'бестселер', 'топ', 'найкращ', 'хіт'];
        foreach ($popularKeywords as $kw) {
            if (str_contains($m, $kw)) {
                return "Бестселери в цій категорії:\n\n";
            }
        }
        
        // Default: no intro, just products
        return "";
    }

    /**
     * Build concise narrative without AI - as a helpful store manager would.
     * Does NOT duplicate product list (cards are shown separately).
     * Provides context, highlights key features, and invites exploration.
     */
    public function buildConciseNarrative(array $products, array $filters, string $originalMessage): string
    {
        if (empty($products)) {
            return "На жаль, не знайшов товарів за вашим запитом. Спробуйте іншу назву або категорію.";
        }

        $count = count($products);
        $m = mb_strtolower($originalMessage);
        
        // Determine main category from products
        $categories = array_filter(array_unique(array_column($products, 'category_path')));
        $mainCategory = $this->extractMainCategory($categories);
        
        // Extract price range
        $prices = array_filter(array_column($products, 'price'));
        $priceRange = '';
        if (!empty($prices)) {
            $minPrice = min($prices);
            $maxPrice = max($prices);
            if ($minPrice === $maxPrice) {
                $priceRange = round($minPrice) . ' ₴';
            } else {
                $priceRange = round($minPrice) . ' — ' . round($maxPrice) . ' ₴';
            }
        }
        
        // Build contextual message based on search type
        $narrative = $this->buildManagerStyleNarrative($products, $count, $mainCategory, $priceRange, $filters, $m);
        
        // Add call-to-action
        $cta = $this->buildContextualCTA($filters, $count, $mainCategory);
        
        return $narrative . "\n\n" . $cta;
    }
    
    /**
     * Build narrative as a helpful store manager would.
     */
    protected function buildManagerStyleNarrative(
        array $products,
        int $count,
        string $mainCategory,
        string $priceRange,
        array $filters,
        string $message
    ): string {
        // Single product - highlight its features
        if ($count === 1) {
            return $this->buildSingleProductHighlight($products[0]);
        }
        
        // Multiple products - provide overview
        $parts = [];
        
        // Quantity context
        if ($count <= 3) {
            $parts[] = "Знайшов {$count} " . $this->pluralize($count, 'варіант', 'варіанти', 'варіантів');
        } else {
            $parts[] = "Ось топ-{$count} варіантів";
        }
        
        // Category context (simplified, no full path)
        if ($mainCategory && !str_contains($message, mb_strtolower($mainCategory))) {
            $parts[] = "з категорії «{$mainCategory}»";
        }
        
        // Price context
        if ($priceRange && empty($filters['budget_max'])) {
            $parts[] = "в діапазоні {$priceRange}";
        }
        
        $intro = implode(' ', $parts) . '.';
        
        // Add product highlights if available
        $highlights = $this->extractProductHighlights($products);
        if (!empty($highlights)) {
            $intro .= "\n\n💡 " . implode("\n💡 ", $highlights);
        }
        
        return $intro;
    }
    
    /**
     * Build highlight for a single product.
     */
    protected function buildSingleProductHighlight(array $product): string
    {
        $title = $product['title'] ?? 'Товар';
        $price = isset($product['price']) ? round($product['price']) . ' ₴' : '';
        $inStock = ($product['in_stock'] ?? false) ? '✅ В наявності' : '⏳ Під замовлення';
        
        $parts = ["Знайшов для вас: **{$title}**"];
        if ($price) {
            $parts[] = "Ціна: {$price}";
        }
        $parts[] = $inStock;
        
        // Extract key features from description
        $features = $this->extractKeyFeatures($product);
        if (!empty($features)) {
            $parts[] = "\n🔹 " . implode("\n🔹 ", $features);
        }
        
        return implode("\n", $parts);
    }
    
    /**
     * Extract key features from product description/attributes.
     */
    protected function extractKeyFeatures(array $product, int $limit = 3): array
    {
        $features = [];
        
        // Try to get from attributes first
        $attrs = $product['attrs'] ?? $product['attributes'] ?? [];
        if (is_array($attrs)) {
            foreach ($attrs as $key => $value) {
                if (is_string($value) && mb_strlen($value) > 2 && mb_strlen($value) < 100) {
                    $features[] = ucfirst($key) . ': ' . $value;
                    if (count($features) >= $limit) break;
                }
            }
        }
        
        // Fallback to description snippets
        if (empty($features)) {
            $desc = $product['description'] ?? '';
            if ($desc) {
                // Extract first meaningful sentence
                $sentences = preg_split('/[.!?]+/u', $desc, 3, PREG_SPLIT_NO_EMPTY);
                foreach ($sentences as $s) {
                    $s = trim($s);
                    if (mb_strlen($s) > 20 && mb_strlen($s) < 150) {
                        $features[] = $s;
                        if (count($features) >= $limit) break;
                    }
                }
            }
        }
        
        return $features;
    }
    
    /**
     * Extract highlights from multiple products.
     */
    protected function extractProductHighlights(array $products): array
    {
        $highlights = [];
        
        // Find price extremes worth mentioning
        $prices = array_filter(array_column($products, 'price'));
        if (count($prices) >= 2) {
            $cheapest = array_filter($products, fn($p) => ($p['price'] ?? 0) == min($prices));
            $cheapest = reset($cheapest);
            if ($cheapest) {
                $name = mb_substr($cheapest['title'] ?? '', 0, 40);
                $highlights[] = "Найдоступніший: {$name} — " . round(min($prices)) . ' ₴';
            }
        }
        
        // Find bestseller/popular if available
        $popular = array_filter($products, fn($p) => ($p['popularity'] ?? 0) > 50 || ($p['orders_count'] ?? 0) > 10);
        if (!empty($popular)) {
            $best = reset($popular);
            $name = mb_substr($best['title'] ?? '', 0, 40);
            if (!str_contains($highlights[0] ?? '', $name)) {
                $highlights[] = "Популярний вибір: {$name}";
            }
        }
        
        return array_slice($highlights, 0, 2);
    }
    
    /**
     * Extract main category name (last segment of path).
     */
    protected function extractMainCategory(array $categories): string
    {
        if (empty($categories)) {
            return '';
        }
        
        $firstCat = reset($categories);
        $parts = explode('/', $firstCat);
        
        // Return the most specific (last) category
        return trim(end($parts));
    }
    
    /**
     * Build contextual call-to-action.
     */
    protected function buildContextualCTA(array $filters, int $count, string $category): string
    {
        $ctas = [];
        
        // Budget filter suggestion
        if (empty($filters['budget_max']) && empty($filters['budget_min'])) {
            $ctas[] = "уточнити бюджет";
        }
        
        // Color filter suggestion  
        if (empty($filters['color'])) {
            $ctas[] = "вибрати колір";
        }
        
        // More results suggestion
        if ($count >= 3) {
            $ctas[] = "показати ще варіанти";
        }
        
        if (empty($ctas)) {
            return "Є питання по цих товарах?";
        }
        
        return "Можу " . implode(', ', array_slice($ctas, 0, 2)) . " — або питайте про деталі!";
    }
    
    /**
     * Ukrainian pluralization helper.
     */
    protected function pluralize(int $n, string $one, string $few, string $many): string
    {
        $n = abs($n) % 100;
        if ($n >= 11 && $n <= 19) return $many;
        $n = $n % 10;
        if ($n === 1) return $one;
        if ($n >= 2 && $n <= 4) return $few;
        return $many;
    }

    /**
     * Build comparison narrative for two products.
     */
    public function buildComparisonNarrative(array $products, string $originalMessage): string
    {
        // Dedup by title and pick best matching pair
        $uniq = [];

        foreach ($products as $p) {
            $title = trim((string) ($p['title'] ?? ''));

            if ($title === '') {
                continue;
            }

            $key = mb_strtolower($title);

            if (isset($uniq[$key])) {
                continue;
            }

            $uniq[$key] = $p;
        }

        $products = array_values($uniq);

        if (count($products) === 1) {
            $p = $products[0];
            $price = isset($p['price']) ? round((float) $p['price']) . ' ₴' : 'ціна не вказана';
            $cat = trim((string) ($p['category_path'] ?? ''));
            $line = ($p['title'] ?? 'Товар') . ' — ' . $price . ($cat ? " ({$cat})" : '');
            return $line . "\n" . "Потрібно показати альтернативу для порівняння?";
        }

        [$a, $b] = $this->pickComparisonPair($products, $originalMessage);

        $titleA = trim((string) ($a['title'] ?? 'Товар A'));
        $titleB = trim((string) ($b['title'] ?? 'Товар B'));
        $priceA = $this->formatPrice($a['price'] ?? null);
        $priceB = $this->formatPrice($b['price'] ?? null);
        $catA = trim((string) ($a['category_path'] ?? ''));
        $catB = trim((string) ($b['category_path'] ?? ''));

        $lines = [];
        $lines[] = "1) {$titleA} — {$priceA}" . ($catA ? " ({$catA})" : '');

        $factsA = $this->formatProductFacts($a);
        if (!empty($factsA)) {
            $lines[] = '   Факти: ' . implode('; ', $factsA);
        }

        $lines[] = "2) {$titleB} — {$priceB}" . ($catB ? " ({$catB})" : '');

        $factsB = $this->formatProductFacts($b);
        if (!empty($factsB)) {
            $lines[] = '   Факти: ' . implode('; ', $factsB);
        }

        $diffs = $this->formatComparisonDiffs($a, $b);
        if (!empty($diffs)) {
            $lines[] = 'Різниця: ' . implode('; ', $diffs);
        }

        $cta = "Потрібно іншу пару для порівняння або показати сумісні аксесуари?";
        return implode("\n", $lines) . "\n" . $cta;
    }

    /**
     * Generate AI follow-up question.
     */
    public function generateFollowUpQuestion(
        string $originalMessage,
        string $searchQuery,
        array $filters,
        array $products
    ): string {
        // Check if products have real diversity worth asking questions about
        if (!$this->hasProductDiversity($products)) {
            return '';
        }

        $productTitles = array_slice(array_column($products, 'title'), 0, 5);
        $categories = array_unique(array_column($products, 'category_path'));
        $colors = array_filter(array_unique(array_column($products, 'color')));
        $prices = array_column($products, 'price');

        $productsContext = implode(', ', array_slice($productTitles, 0, 3));
        $categoryContext = implode(', ', array_slice($categories, 0, 2));
        $colorContext = !empty($colors) ? implode(', ', array_slice($colors, 0, 3)) : 'різні';
        $priceRange = !empty($prices) ? round(min($prices)) . '-' . round(max($prices)) . ' грн' : '';

        $prompt = "Магазин Contractor — тактичне військове спорядження для ЗСУ, правоохоронців, добровольців.

Користувач шукав: \"{$searchQuery}\"
Знайдено товари: {$productsContext}
Категорії: {$categoryContext}
Кольори: {$colorContext}
Ціновий діапазон: {$priceRange}

Згенеруй ОДНЕ природне уточнююче питання (до 20 слів).

Питай про те, що допоможе вибрати конкретний товар з цього списку.
Ніяких загальних питань! Дивися на реальні товари.

Будь природним і корисним. Не використовуй шаблони.
Поверни ТІЛЬКИ текст питання українською, без лапок.";

        try {
            $response = $this->aiRouter->callOpenAI($prompt, 0.7, 60);
            $question = trim($response, " \n\r\t\"'");

            if (empty($question) || mb_strlen($question) > 150) {
                return '';
            }

            return $question;
        } catch (\Throwable $e) {
            Log::warning('NarrativeBuilder: AI follow-up failed', ['error' => $e->getMessage()]);
            return '';
        }
    }

    /**
     * Check if products have diversity worth asking about.
     */
    private function hasProductDiversity(array $products): bool
    {
        if (count($products) < 3) {
            return false;
        }

        $categories = array_unique(array_column($products, 'category_path'));
        $colors = array_filter(array_unique(array_column($products, 'color')));
        $prices = array_column($products, 'price');

        $priceRange = !empty($prices) ? (max($prices) - min($prices)) : 0;

        // Don't ask if: 1 category + ≤2 colors + price range <1000
        if (count($categories) === 1 && count($colors) <= 2 && $priceRange < 1000) {
            return false;
        }

        return true;
    }

    /**
     * Detect product flow type from message.
     */
    private function detectProductFlow(string $message): string
    {
        $m = mb_strtolower($message);

        $comparisonKeywords = ['порівняй', 'чим різниця', 'чим відрізня', 'чим відрізняється', 'vs', 'відмінність', 'різниця'];
        foreach ($comparisonKeywords as $kw) {
            if (str_contains($m, $kw)) {
                return 'comparison';
            }
        }

        $followupKeywords = ['докупити', 'що ще потрібно', 'комплект', 'додатков', 'аксесуар', 'до цієї', 'сумісн'];
        foreach ($followupKeywords as $kw) {
            if (str_contains($m, $kw)) {
                return 'followup';
            }
        }

        $detailKeywords = ['що за', 'розкажи про', 'опис', 'характеристик', 'підійде', 'підходить'];
        foreach ($detailKeywords as $kw) {
            if (str_contains($m, $kw)) {
                return 'details';
            }
        }

        return 'discovery';
    }

    /**
     * Pick best pair for comparison.
     */
    private function pickComparisonPair(array $products, string $message): array
    {
        $scores = [];

        foreach ($products as $idx => $p) {
            $scores[$idx] = $this->scoreProductByMessage($p, $message);
        }

        arsort($scores);
        $indices = array_keys($scores);

        $first = $products[$indices[0]] ?? ($products[0] ?? []);
        $second = $products[$indices[1]] ?? ($products[1] ?? $first);

        if (($first['id'] ?? null) === ($second['id'] ?? null) && isset($products[1])) {
            $second = $products[1];
        }

        return [$first, $second];
    }

    /**
     * Score product relevance to message.
     */
    private function scoreProductByMessage(array $product, string $message): int
    {
        $m = mb_strtolower($message);
        $title = mb_strtolower((string) ($product['title'] ?? ''));
        $brand = mb_strtolower((string) ($product['brand'] ?? ''));
        $score = 0;

        $tokens = preg_split('/[^a-zа-я0-9]+/ui', $m, -1, PREG_SPLIT_NO_EMPTY);
        $tokens = array_filter($tokens, fn($t) => mb_strlen($t) >= 3);

        foreach ($tokens as $t) {
            if (!empty($brand) && str_contains($brand, $t)) {
                $score += 5;
            }
            if (str_contains($title, $t)) {
                $score += 2;
            }
        }

        return $score;
    }

    /**
     * Format price value.
     */
    public function formatPrice($price): string
    {
        if ($price === null || $price === '') {
            return 'ціна не вказана';
        }
        if (!is_numeric($price)) {
            return 'ціна не вказана';
        }
        return round((float) $price) . ' ₴';
    }

    /**
     * Format product facts for comparison.
     */
    private function formatProductFacts(array $p, int $limit = 2): array
    {
        $facts = [];

        $level = $this->extractLevel($p);
        if ($level) {
            $facts[] = "Level: {$level}";
        }

        $chars = $p['characteristics'] ?? [];
        if (is_array($chars) && !empty($chars)) {
            foreach ($chars as $k => $v) {
                if (count($facts) >= $limit) {
                    break;
                }
                if (!is_string($k) || $k === '' || $v === null) {
                    continue;
                }
                $vText = is_scalar($v) ? (string) $v : '';
                if ($vText === '') {
                    continue;
                }
                $facts[] = trim($k) . ': ' . trim($vText);
            }
        }

        if (count($facts) < $limit) {
            $desc = trim((string) ($p['description'] ?? ''));
            if ($desc !== '') {
                $facts[] = mb_substr($desc, 0, 80) . (mb_strlen($desc) > 80 ? '…' : '');
            }
        }

        return array_slice($facts, 0, $limit);
    }

    /**
     * Format comparison differences.
     */
    private function formatComparisonDiffs(array $a, array $b): array
    {
        $diffs = [];

        $levelA = $this->extractLevel($a);
        $levelB = $this->extractLevel($b);
        if ($levelA && $levelB && $levelA !== $levelB) {
            $diffs[] = "Рівень: {$levelA} vs {$levelB}";
        }

        $brandA = trim((string) ($a['brand'] ?? ''));
        $brandB = trim((string) ($b['brand'] ?? ''));
        if ($brandA && $brandB && mb_strtolower($brandA) !== mb_strtolower($brandB)) {
            $diffs[] = "Бренд: {$brandA} vs {$brandB}";
        }

        $catA = trim((string) ($a['category_path'] ?? ''));
        $catB = trim((string) ($b['category_path'] ?? ''));
        if ($catA && $catB && mb_strtolower($catA) !== mb_strtolower($catB)) {
            $diffs[] = "Категорія: {$catA} vs {$catB}";
        }

        $colorA = trim((string) ($a['color'] ?? ''));
        $colorB = trim((string) ($b['color'] ?? ''));
        if ($colorA && $colorB && mb_strtolower($colorA) !== mb_strtolower($colorB)) {
            $diffs[] = "Колір: {$colorA} vs {$colorB}";
        }

        $priceA = $a['price'] ?? null;
        $priceB = $b['price'] ?? null;
        if (is_numeric($priceA) && is_numeric($priceB) && (float) $priceA !== (float) $priceB) {
            $diff = round(abs((float) $priceA - (float) $priceB));
            $diffs[] = "Ціна: {$this->formatPrice($priceA)} vs {$this->formatPrice($priceB)} (різниця ~{$diff} ₴)";
        }

        return array_slice($diffs, 0, 3);
    }

    /**
     * Extract protection level from product.
     */
    private function extractLevel(array $product): ?string
    {
        $haystack = mb_strtolower(trim((string) (($product['title'] ?? '') . ' ' . ($product['category_path'] ?? ''))));

        if ($haystack === '') {
            return null;
        }

        if (preg_match('/level\s*([1-9])/iu', $haystack, $m)) {
            return 'Level ' . $m[1];
        }

        return null;
    }

    /**
     * Build detailed message about a single product.
     */
    public function buildProductDetailMessage(array $p): string
    {
        $title = $p['title'] ?? 'Товар';
        $price = $this->formatPrice($p['price'] ?? null);
        $category = $p['category_path'] ?? '';
        $chars = $p['characteristics'] ?? [];
        $desc = trim((string) ($p['description'] ?? ''));

        $lines = [];
        $lines[] = $title;
        $lines[] = $price;

        if (!empty($category)) {
            $lines[] = 'Категорія: ' . $category;
        }

        if (is_array($chars) && !empty($chars)) {
            $charLines = $this->formatCharacteristics($chars, 6);
            if (!empty($charLines)) {
                $lines[] = 'Характеристики:';
                $lines[] = $charLines;
            }
        }

        if ($desc !== '') {
            $summary = $this->summarizeDescription($desc, 8, 900);
            if ($summary !== '') {
                $lines[] = 'З опису:';
                $lines[] = $summary;
            }
        }

        $lines[] = "\nПотрібно порівняти з іншим товаром або подивитися аксесуари?";

        return implode("\n", $lines);
    }

    /**
     * Format characteristics for display.
     */
    private function formatCharacteristics(array $characteristics, int $limit = 3): string
    {
        if (empty($characteristics)) {
            return '';
        }

        $out = [];
        $count = 0;

        foreach ($characteristics as $key => $value) {
            if ($count >= $limit) {
                break;
            }
            if (is_string($key) && (is_string($value) || is_numeric($value))) {
                $out[] = "• {$key}: {$value}";
                $count++;
                continue;
            }
            if (is_string($value)) {
                $out[] = "• {$value}";
                $count++;
            }
        }

        return implode("\n", $out);
    }

    /**
     * Summarize description into bullet points.
     */
    private function summarizeDescription(string $desc, int $maxBullets = 6, int $maxChars = 900): string
    {
        $trimmed = trim($desc);

        if ($trimmed === '') {
            return '';
        }

        if (mb_strlen($trimmed) > $maxChars) {
            $trimmed = mb_substr($trimmed, 0, $maxChars);
        }

        $parts = preg_split('/[\r\n]+/u', $trimmed) ?: [];
        $bullets = [];

        foreach ($parts as $part) {
            $clean = trim($part, " \t-•");
            if ($clean === '') {
                continue;
            }
            $bullets[] = '- ' . $clean;
            if (count($bullets) >= $maxBullets) {
                break;
            }
        }

        if (empty($bullets)) {
            $sentences = preg_split('/(?<=[.!?])\s+/u', $trimmed) ?: [];
            foreach ($sentences as $s) {
                $clean = trim($s, " \t-•");
                if ($clean === '') {
                    continue;
                }
                $bullets[] = '- ' . $clean;
                if (count($bullets) >= $maxBullets) {
                    break;
                }
            }
        }

        return implode("\n", $bullets);
    }
}
