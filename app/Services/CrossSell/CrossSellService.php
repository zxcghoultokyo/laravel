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
    protected ?int $tenantId = null;
    
    public function __construct(AiRouter $aiRouter)
    {
        $this->aiRouter = $aiRouter;
    }
    
    /**
     * Set tenant ID for filtering
     */
    public function setTenantId(?int $tenantId): self
    {
        $this->tenantId = $tenantId;
        return $this;
    }
    
    /**
     * Get current tenant ID (from product or explicit setting)
     */
    protected function getTenantId(?Product $product = null): ?int
    {
        return $this->tenantId ?? $product?->tenant_id;
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
        $seenIds = [$product->id]; // Exclude the source product itself
        $seenArticles = [$product->article]; // Also track by article for deduplication
        $seenParentArticles = [$product->parent_article ?? $product->article]; // Track parent_article to avoid size variants
        $tenantId = $this->getTenantId($product);
        
        // Helper to check if product is duplicate (by id, article, or parent_article)
        $isDuplicate = function($p) use (&$seenIds, &$seenArticles, &$seenParentArticles) {
            if (!$p) return true;
            if (in_array($p->id, $seenIds)) return true;
            if (in_array($p->article, $seenArticles)) return true;
            // Check parent_article to avoid showing same product in different sizes
            $parentArt = $p->parent_article ?? $p->article;
            if (in_array($parentArt, $seenParentArticles)) return true;
            return false;
        };
        
        // Helper to mark product as seen
        $markSeen = function($p) use (&$seenIds, &$seenArticles, &$seenParentArticles) {
            $seenIds[] = $p->id;
            $seenArticles[] = $p->article;
            $seenParentArticles[] = $p->parent_article ?? $p->article;
        };
        
        // 1. First check direct product cross-sells (manual links)
        $directSuggestions = $this->getDirectCrossSells($product, $tenantId);
        foreach ($directSuggestions as $suggestion) {
            $p = $suggestion['product'] ?? null;
            if (!$isDuplicate($p)) {
                $markSeen($p);
                $suggestions->push($suggestion);
            }
        }
        
        // 2. If not enough, use category-based rules
        if ($suggestions->count() < $limit) {
            $categoryKey = $this->extractCategoryKey($product->category_path);
            $categorySuggestions = $this->getCategoryBasedSuggestions($product, $categoryKey, $limit - $suggestions->count(), $tenantId);
            foreach ($categorySuggestions as $suggestion) {
                $p = $suggestion['product'] ?? null;
                if (!$isDuplicate($p)) {
                    $markSeen($p);
                    $suggestions->push($suggestion);
                }
            }
        }
        
        // 3. If still not enough, use AI-based suggestions (same ai_product_type)
        if ($suggestions->count() < $limit) {
            $aiSuggestions = $this->getAiBasedSuggestions($product, $limit - $suggestions->count(), $seenIds, $tenantId);
            foreach ($aiSuggestions as $suggestion) {
                $p = $suggestion['product'] ?? null;
                if (!$isDuplicate($p)) {
                    $markSeen($p);
                    $suggestions->push($suggestion);
                }
            }
        }
        
        return $suggestions->take($limit)->values()->toArray();
    }
    
    /**
     * Get direct cross-sells from database
     */
    protected function getDirectCrossSells(Product $product, ?int $tenantId = null): Collection
    {
        $query = ProductCrossSell::where('product_id', $product->id)
            ->where('is_active', true)
            ->orderBy('priority', 'desc')
            ->with('crossSellProduct');
        
        $crossSells = $query->get();
            
        return $crossSells->map(function ($cs) {
            return [
                'product' => $cs->crossSellProduct,
                'reason' => $cs->reason,
                'type' => $cs->type,
            ];
        })->filter(function($item) use ($tenantId) {
            $p = $item['product'] ?? null;
            if (!$p || !$p->in_stock) return false;
            // Filter by tenant if specified
            if ($tenantId && $p->tenant_id && $p->tenant_id != $tenantId) return false;
            return true;
        });
    }
    
    /**
     * Get suggestions based on category rules - now uses DB categories + AI
     */
    protected function getCategoryBasedSuggestions(Product $product, ?string $categoryKey, int $limit, ?int $tenantId = null): Collection
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
                $limit,
                $tenantId
            );
        }
        
        // 2. Try AI to determine accessories dynamically
        $aiSuggestions = $this->getAiAccessorySuggestions($product, $limit, $tenantId);
        if ($aiSuggestions->isNotEmpty()) {
            return $aiSuggestions;
        }
        
        // 3. Fallback to hardcoded rules
        $fallbackCategories = $this->fallbackRules[$categoryKey] ?? [];
        if (empty($fallbackCategories)) {
            return collect();
        }
        
        $rules = array_map(fn($cat) => ['category' => $cat, 'reason' => $cat], $fallbackCategories);
        return $this->findProductsByRules($rules, $product, $limit, $tenantId);
    }
    
    /**
     * Use AI to determine what accessories would be good for this product
     */
    protected function getAiAccessorySuggestions(Product $product, int $limit, ?int $tenantId = null): Collection
    {
        // Cache key based on category path and tenant
        $cacheKey = 'cross_sell_ai_' . ($tenantId ?? 'all') . '_' . md5($product->category_path);
        
        $aiCategories = Cache::remember($cacheKey, 3600, function() use ($product) {
            return $this->askAiForAccessories($product);
        });
        
        if (empty($aiCategories)) {
            return collect();
        }
        
        return $this->findProductsByAiCategories($aiCategories, $product, $limit, $tenantId);
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
    protected function findProductsByAiCategories(array $aiCategories, Product $product, int $limit, ?int $tenantId = null): Collection
    {
        $suggestions = collect();
        
        foreach ($aiCategories as $item) {
            if ($suggestions->count() >= $limit) break;
            
            $category = $item['category'] ?? '';
            $reason = $item['reason'] ?? 'доповнює покупку';
            
            if (empty($category)) continue;
            
            $query = Product::where('in_stock', true)
                ->where('id', '!=', $product->id)
                ->where('category_path', 'LIKE', '%' . $category . '%');
            
            // Filter by tenant
            if ($tenantId) {
                $query->where('tenant_id', $tenantId);
            }
            
            $targetProducts = $query->orderByDesc('popularity')
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
    protected function findProductsByRules(array $rules, Product $product, int $limit, ?int $tenantId = null): Collection
    {
        $suggestions = collect();
        
        foreach ($rules as $rule) {
            if ($suggestions->count() >= $limit) break;
            
            $query = Product::where('in_stock', true)
                ->where('id', '!=', $product->id)
                ->where('category_path', '!=', $product->category_path)
                ->where('category_path', 'LIKE', '%' . $rule['category'] . '%');
            
            // Filter by tenant
            if ($tenantId) {
                $query->where('tenant_id', $tenantId);
            }
            
            $targetProducts = $query->orderByDesc('popularity')
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
    protected function getAiBasedSuggestions(Product $product, int $limit, array $excludeIds = [], ?int $tenantId = null): Collection
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
        
        $query = Product::whereHas('aiIndex', function($q) use ($complementaryTypes) {
            $q->whereIn('ai_product_type', $complementaryTypes);
        })
        ->where('in_stock', true)
        ->where('id', '!=', $product->id)
        ->whereNotIn('id', $excludeIds);
        
        // Filter by tenant
        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }
        
        $products = $query->orderByDesc('popularity')
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
