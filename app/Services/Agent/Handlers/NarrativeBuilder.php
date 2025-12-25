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

        // Detect context-aware intro based on user message
        $intro = $this->detectContextualIntro($originalMessage, $filters, count($products));

        // Concise deterministic narrative: no LLM, no hallucinations
        return $intro . $this->buildConciseNarrative($productsForPrompt, $filters, $originalMessage);
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
     * Build concise narrative without AI.
     */
    public function buildConciseNarrative(array $products, array $filters, string $originalMessage): string
    {
        // Sort by price ascending when available
        usort($products, fn($a, $b) => ($a['price'] ?? PHP_INT_MAX) <=> ($b['price'] ?? PHP_INT_MAX));

        $lines = [];
        $seen = [];

        foreach ($products as $p) {
            $title = trim((string) ($p['title'] ?? 'Товар'));

            if ($title === '' || isset($seen[mb_strtolower($title)])) {
                continue;
            }

            $seen[mb_strtolower($title)] = true;

            $price = isset($p['price']) ? round((float) $p['price']) . ' ₴' : 'ціна не вказана';

            // Pick 1–2 real facts: category_path and short description if present
            $facts = [];
            $cat = trim((string) ($p['category_path'] ?? ''));

            if ($cat !== '') {
                $facts[] = $cat;
            }

            $desc = trim((string) ($p['description'] ?? ''));

            if ($desc !== '') {
                $facts[] = mb_substr($desc, 0, 90) . (mb_strlen($desc) > 90 ? '…' : '');
            }

            if (empty($facts)) {
                $facts[] = 'деталі не вказано';
            }

            $lines[] = "- {$title} — {$price}. " . implode(' / ', array_slice($facts, 0, 2));

            if (count($lines) >= 3) {
                break;
            }
        }

        if (empty($lines)) {
            return "Наразі немає товарів за цим запитом";
        }

        $cta = "Звузити за бюджетом чи кольором, або показати ще?";

        return implode("\n", $lines) . "\n" . $cta;
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
