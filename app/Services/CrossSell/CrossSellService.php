<?php

namespace App\Services\CrossSell;

use App\Models\Product;
use App\Models\ProductCrossSell;
use App\Models\CrossSellRule;
use App\Models\Category;
use App\Services\Ai\AiRouter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class CrossSellService
{
    protected AiRouter $aiRouter;
    
    public function __construct(AiRouter $aiRouter)
    {
        $this->aiRouter = $aiRouter;
    }
    
    /**
     * Fallback rules if DB and AI both fail
     */
    protected array $fallbackRules = [
        'plate_carriers' => ['підсумки', 'балістичні плити', 'бронеплити'],
        'tactical_pouches' => ['медичні підсумки', 'збройові підсумки'],
        'boots' => ['шкарпетки', 'устілки'],
        'backpacks' => ['підсумки', 'гідратор'],
        'helmets' => ['чохол на шолом', 'кріплення'],
        'gloves' => ['рукавиці', 'захисні окуляри', 'балаклава'],
        'rukavytsi' => ['рукавиці', 'захисні окуляри', 'балаклава'],
        // Armor plates cross-sell
        'broneplity' => ['плитоноска', 'чохол для плит', 'бокові плити'],
        'broneplastyny' => ['плитоноска', 'чохол для плит', 'бокові плити'],
        'armor_plates' => ['плитоноска', 'чохол для плит', 'бокові плити'],
        'ballistyka' => ['плитоноска', 'бронежилет', 'м\'яка балістика'],
    ];

    /**
     * Get cross-sell suggestions for a product
     */
    public function getSuggestions(Product $product, int $limit = 3): array
    {
        $suggestions = collect();
        
        // 1. First check direct product cross-sells (manual links)
        $directSuggestions = $this->getDirectCrossSells($product);
        $suggestions = $suggestions->merge($directSuggestions);
        
        // 2. If not enough, use category-based rules
        if ($suggestions->count() < $limit) {
            $categoryKey = $this->extractCategoryKey($product->category_path);
            $categorySuggestions = $this->getCategoryBasedSuggestions($product, $categoryKey, $limit - $suggestions->count());
            $suggestions = $suggestions->merge($categorySuggestions);
        }
        
        // 3. If still not enough, use AI-based suggestions (same ai_product_type)
        if ($suggestions->count() < $limit) {
            $aiSuggestions = $this->getAiBasedSuggestions($product, $limit - $suggestions->count(), $suggestions->pluck('product.id')->toArray());
            $suggestions = $suggestions->merge($aiSuggestions);
        }
        
        return $suggestions->take($limit)->values()->toArray();
    }
    
    /**
     * Get direct cross-sells from database
     */
    protected function getDirectCrossSells(Product $product): Collection
    {
        $crossSells = ProductCrossSell::where('product_id', $product->id)
            ->where('is_active', true)
            ->orderBy('priority', 'desc')
            ->with('crossSellProduct')
            ->get();
            
        return $crossSells->map(function ($cs) {
            return [
                'product' => $cs->crossSellProduct,
                'reason' => $cs->reason,
                'type' => $cs->type,
            ];
        })->filter(fn($item) => $item['product'] && $item['product']->in_stock);
    }
    
    /**
     * Get suggestions based on category rules - now uses DB categories + AI
     */
    protected function getCategoryBasedSuggestions(Product $product, ?string $categoryKey, int $limit): Collection
    {
        // 1. Try DB rules first
        $dbRules = CrossSellRule::where('source_category', $categoryKey)
            ->where('is_active', true)
            ->orderBy('priority', 'desc')
            ->get();
            
        if ($dbRules->isNotEmpty()) {
            return $this->findProductsByRules(
                $dbRules->map(fn($r) => ['category' => $r->target_category, 'reason' => $r->reason])->toArray(),
                $product,
                $limit
            );
        }
        
        // 2. Try AI to determine accessories dynamically
        $aiSuggestions = $this->getAiAccessorySuggestions($product, $limit);
        if ($aiSuggestions->isNotEmpty()) {
            return $aiSuggestions;
        }
        
        // 3. Fallback to hardcoded rules
        $fallbackCategories = $this->fallbackRules[$categoryKey] ?? [];
        if (empty($fallbackCategories)) {
            return collect();
        }
        
        $rules = array_map(fn($cat) => ['category' => $cat, 'reason' => $cat], $fallbackCategories);
        return $this->findProductsByRules($rules, $product, $limit);
    }
    
    /**
     * Use AI to determine what accessories would be good for this product
     */
    protected function getAiAccessorySuggestions(Product $product, int $limit): Collection
    {
        // Cache key based on category path
        $cacheKey = 'cross_sell_ai_' . md5($product->category_path);
        
        $aiCategories = Cache::remember($cacheKey, 3600, function() use ($product) {
            return $this->askAiForAccessories($product);
        });
        
        if (empty($aiCategories)) {
            return collect();
        }
        
        return $this->findProductsByAiCategories($aiCategories, $product, $limit);
    }
    
    /**
     * Ask GPT what accessories would complement this product
     */
    protected function askAiForAccessories(Product $product): array
    {
        try {
            // Get available categories from DB
            $availableCategories = Category::where('is_active', true)
                ->where('products_count', '>', 0)
                ->pluck('path')
                ->toArray();
            
            if (empty($availableCategories)) {
                return [];
            }
            
            $prompt = sprintf(
                "Користувач купує тактичний товар категорії: %s\nНазва: %s\n\nВибери 2-3 категорії СУПУТНІХ ТОВАРІВ які б логічно доповнили цю покупку.\n\nВАЖЛИВО:\n- Вибирай РЕЛЕВАНТНІ аксесуари з однієї сфери використання\n- Для рукавичок: балаклави, захисні окуляри, шапки\n- Для плитоносок: підсумки, панелі, бронеплити\n- Для БРОНЕПЛИТ/БРОНЕПЛАСТИН: плитоноски, чохли для плит, бокові плити\n- Для взуття: шкарпетки, устілки\n- НЕ пропонуй одяг (штани, куртки, шорти) до аксесуарів\n- НЕ пропонуй рюкзаки до бронеплит!\n\nДоступні категорії:\n%s\n\nВідповідь у форматі JSON масиву з об'єктами: [{\"category\": \"назва категорії точно як у списку\", \"reason\": \"чому це корисно\"}]",
                $product->category_path,
                $product->title,
                implode("\n", array_slice($availableCategories, 0, 50)) // Limit to 50 categories
            );
            
            $response = $this->aiRouter->callOpenAI($prompt, 0.3, 500);
            
            // Parse JSON from response
            if (preg_match('/\[.*\]/s', $response, $matches)) {
                $parsed = json_decode($matches[0], true);
                if (is_array($parsed)) {
                    return $parsed;
                }
            }
            
            return [];
        } catch (\Throwable $e) {
            Log::warning('AI cross-sell failed', ['error' => $e->getMessage()]);
            return [];
        }
    }
    
    /**
     * Find products by AI-suggested categories
     */
    protected function findProductsByAiCategories(array $aiCategories, Product $product, int $limit): Collection
    {
        $suggestions = collect();
        
        foreach ($aiCategories as $item) {
            if ($suggestions->count() >= $limit) break;
            
            $category = $item['category'] ?? '';
            $reason = $item['reason'] ?? 'доповнює покупку';
            
            if (empty($category)) continue;
            
            $targetProducts = Product::where('in_stock', true)
                ->where('id', '!=', $product->id)
                ->where('category_path', 'LIKE', '%' . $category . '%')
                ->orderByDesc('popularity')
                ->limit(2)
                ->get();
            
            foreach ($targetProducts as $p) {
                if ($suggestions->count() >= $limit) break;
                $suggestions->push([
                    'product' => $p,
                    'reason' => $reason,
                    'type' => 'ai_suggestion',
                ]);
            }
        }
        
        return $suggestions;
    }
    
    /**
     * Find products by category rules
     */
    protected function findProductsByRules(array $rules, Product $product, int $limit): Collection
    {
        $suggestions = collect();
        
        foreach ($rules as $rule) {
            if ($suggestions->count() >= $limit) break;
            
            $targetProducts = Product::where('in_stock', true)
                ->where('id', '!=', $product->id)
                ->where('category_path', '!=', $product->category_path)
                ->where('category_path', 'LIKE', '%' . $rule['category'] . '%')
                ->orderByDesc('popularity')
                ->limit(2)
                ->get();
            
            foreach ($targetProducts as $p) {
                if ($suggestions->count() >= $limit) break;
                $suggestions->push([
                    'product' => $p,
                    'reason' => $rule['reason'],
                    'type' => 'category_rule',
                ]);
            }
        }
        
        return $suggestions;
    }
    
    /**
     * Get AI-based suggestions (complementary categories)
     */
    protected function getAiBasedSuggestions(Product $product, int $limit, array $excludeIds = []): Collection
    {
        $aiIndex = $product->aiIndex;
        if (!$aiIndex) {
            return collect();
        }
        
        // Find products that are commonly bought together or complement this product
        $complementaryTypes = $this->getComplementaryTypes($aiIndex->ai_product_type);
        
        if (empty($complementaryTypes)) {
            return collect();
        }
        
        $products = Product::whereHas('aiIndex', function($q) use ($complementaryTypes) {
            $q->whereIn('ai_product_type', $complementaryTypes);
        })
        ->where('in_stock', true)
        ->where('id', '!=', $product->id)
        ->whereNotIn('id', $excludeIds)
        ->orderByDesc('popularity')
        ->limit($limit)
        ->get();
        
        return $products->map(fn($p) => [
            'product' => $p,
            'reason' => 'часто беруть разом',
            'type' => 'ai_suggestion',
        ]);
    }
    
    /**
     * Get complementary product types
     */
    protected function getComplementaryTypes(?string $productType): array
    {
        if (!$productType) return [];
        
        $complementaryMap = [
            'плитоноска' => ['підсумок', 'балістична плита', 'камербанд'],
            'берці' => ['шкарпетки', 'устілки'],
            'рюкзак' => ['підсумок', 'гідратор', 'органайзер'],
            'куртка' => ['флісова кофта', 'термобілизна'],
            'штани' => ['ремінь', 'наколінники'],
            'шолом' => ['чохол на шолом', 'подушки для шолома'],
        ];
        
        foreach ($complementaryMap as $type => $complements) {
            if (stripos($productType, $type) !== false) {
                return $complements;
            }
        }
        
        return [];
    }
    
    /**
     * Extract category key from category path - uses DB categories
     */
    protected function extractCategoryKey(?string $categoryPath): ?string
    {
        if (!$categoryPath) return null;
        
        // Try to find matching category in DB first
        $category = Category::where('path', $categoryPath)
            ->orWhere('path_norm', mb_strtolower($categoryPath))
            ->first();
        
        if ($category) {
            return $category->slug ?? $this->generateSlug($categoryPath);
        }
        
        // Fallback pattern matching for known categories
        $patterns = [
            'плитоноск' => 'plate_carriers',
            'берц' => 'boots',
            'взутт' => 'boots',
            'рюкзак' => 'backpacks',
            'куртк' => 'jackets',
            'штан' => 'pants',
            'шолом' => 'helmets',
            'нагрудн' => 'chest_rigs',
            'підсумок' => 'tactical_pouches',
            'підсумки' => 'tactical_pouches',
            'медичн' => 'medical',
            'рукавиц' => 'gloves',
            'рукавичк' => 'gloves',
            'gloves' => 'gloves',
        ];
        
        $lowerPath = mb_strtolower($categoryPath);
        
        foreach ($patterns as $pattern => $key) {
            if (mb_stripos($lowerPath, $pattern) !== false) {
                return $key;
            }
        }
        
        // Return a generated slug for any category
        return $this->generateSlug($categoryPath);
    }
    
    /**
     * Generate a slug from category path
     */
    protected function generateSlug(string $path): string
    {
        // Take last segment of path
        $parts = explode('/', $path);
        $last = end($parts);
        
        // Simple transliteration
        $slug = mb_strtolower($last);
        $slug = preg_replace('/[^a-zа-яіїєґ0-9]+/u', '_', $slug);
        $slug = trim($slug, '_');
        
        return $slug ?: 'unknown';
    }
    
    /**
     * Format suggestions for chat response
     */
    public function formatForChat(array $suggestions, Product $mainProduct): array
    {
        if (empty($suggestions)) {
            return [];
        }
        
        return [
            'type' => 'cross_sell',
            'title' => 'Разом краще',
            'subtitle' => 'Беруть ' . rand(7, 9) . '/10 покупців',
            'main_product' => [
                'id' => $mainProduct->id,
                'article' => $mainProduct->article,
                'title' => $mainProduct->title,
                'price' => $mainProduct->price,
            ],
            'suggestions' => array_map(function($s) {
                $product = $s['product'];
                
                // Build AI summary from product data
                $summary = $this->buildProductSummary($product);
                
                return [
                    'id' => $product->id,
                    'article' => $product->article,
                    'title' => $product->title,
                    'price' => $product->price,
                    'image' => $this->getProductImage($product),
                    'link' => $product->link,
                    'reason' => $s['reason'],
                    'summary' => $summary,
                    'color' => $product->color,
                    'size' => $product->size,
                ];
            }, $suggestions),
            'hint' => 'Щоб замовити — додайте товар у кошик на сайті',
        ];
    }
    
    /**
     * Build short AI summary from product data (title, description, characteristics)
     */
    protected function buildProductSummary(Product $product): string
    {
        $parts = [];
        
        // 1. Title already contains key info
        $title = $product->title ?? '';
        
        // 2. Extract key characteristics
        $raw = is_array($product->raw) ? $product->raw : [];
        $chars = $raw['characteristics_ua'] ?? $raw['characteristics_ru'] ?? [];
        
        if (!empty($chars) && is_array($chars)) {
            // Take first 2-3 key characteristics
            $keyChars = array_slice($chars, 0, 3);
            foreach ($keyChars as $char) {
                $name = $char['name'] ?? '';
                $value = $char['value'] ?? '';
                if ($name && $value) {
                    $parts[] = "{$name}: {$value}";
                }
            }
        }
        
        // 3. Short description snippet
        $desc = $raw['description_ua'] ?? $raw['description_ru'] ?? '';
        if ($desc) {
            $desc = strip_tags($desc);
            $desc = preg_replace('/\s+/', ' ', $desc);
            $desc = trim($desc);
            if (mb_strlen($desc) > 100) {
                $desc = mb_substr($desc, 0, 100) . '...';
            }
            if ($desc) {
                $parts[] = $desc;
            }
        }
        
        // Combine into short summary
        if (empty($parts)) {
            return '';
        }
        
        return implode(' • ', array_slice($parts, 0, 3));
    }
    
    /**
     * Get product image with fallback
     */
    protected function getProductImage(Product $product): ?string
    {
        // Try images array first
        if (!empty($product->images) && is_array($product->images)) {
            return $product->images[0];
        }
        
        // Try raw pictures
        $raw = is_array($product->raw) ? $product->raw : [];
        $pictures = $raw['pictures'] ?? [];
        if (!empty($pictures) && is_array($pictures)) {
            return $pictures[0]['url'] ?? $pictures[0] ?? null;
        }
        
        return null;
    }
}
