<?php

namespace App\Services\Catalog;

use App\Models\Category;
use App\Models\CategoryAlias;
use App\Scopes\TenantScope;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Service to get category patterns from DB for context extraction.
 * Replaces hardcoded patterns with dynamic DB-based patterns.
 */
class CategoryPatternService
{
    /**
     * Cache TTL in seconds (1 hour)
     */
    private const CACHE_TTL = 3600;

    /**
     * Get category patterns from DB for a specific tenant.
     * Returns array like ['навушник|peltor|comtac' => 'Активні Навушники', ...]
     * 
     * @param int|null $tenantId
     * @return array<string, string>
     */
    public function getPatterns(?int $tenantId = null): array
    {
        $cacheKey = "category_patterns_tenant_{$tenantId}";
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($tenantId) {
            return $this->buildPatternsFromDb($tenantId);
        });
    }

    /**
     * Build patterns from category_aliases table.
     * Groups aliases by category and creates regex patterns.
     */
    protected function buildPatternsFromDb(?int $tenantId): array
    {
        try {
            // Get categories with their aliases
            $query = Category::withoutGlobalScope(TenantScope::class)
                ->with(['aliases' => function ($q) {
                    $q->where('is_active', true)
                      ->where('weight', '>=', 15) // Skip very weak aliases
                      ->orderByDesc('weight');
                }])
                ->where('is_active', true);
            
            if ($tenantId !== null) {
                $query->where('tenant_id', $tenantId);
            }
            
            $categories = $query->get();
            
            $patterns = [];
            
            foreach ($categories as $category) {
                if ($category->aliases->isEmpty()) {
                    continue;
                }
                
                // Get unique normalized phrases
                $phrases = $category->aliases
                    ->pluck('phrase_norm')
                    ->unique()
                    ->filter(fn($p) => mb_strlen($p) >= 3)
                    ->take(10) // Limit to prevent huge regex
                    ->values()
                    ->toArray();
                
                if (empty($phrases)) {
                    continue;
                }
                
                // Create regex pattern from phrases
                // Escape special regex characters and join with |
                $escapedPhrases = array_map(function ($p) {
                    // Remove spaces for partial matching
                    $p = str_replace(' ', '', $p);
                    // Take first significant word/stem
                    if (mb_strlen($p) > 10) {
                        $p = mb_substr($p, 0, 10);
                    }
                    return preg_quote($p, '/');
                }, $phrases);
                
                $pattern = implode('|', array_unique($escapedPhrases));
                
                // Get category display name (last segment of path)
                $pathSegments = explode('/', $category->path);
                $displayName = trim(end($pathSegments));
                
                if ($pattern && $displayName) {
                    $patterns[$pattern] = $displayName;
                }
            }
            
            Log::info('CategoryPatternService: built patterns from DB', [
                'tenant_id' => $tenantId,
                'patterns_count' => count($patterns),
            ]);
            
            return $patterns;
            
        } catch (\Throwable $e) {
            Log::warning('CategoryPatternService: failed to build patterns', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId,
            ]);
            
            // Return fallback hardcoded patterns
            return $this->getFallbackPatterns();
        }
    }

    /**
     * Fallback hardcoded patterns if DB is unavailable.
     */
    public function getFallbackPatterns(): array
    {
        return [
            'плитоноск' => 'плитоноски',
            'шолом|каск' => 'шоломи',
            'берц|черевик' => 'берці',
            'рюкзак' => 'рюкзаки',
            'підсумок|підсумк' => 'підсумки',
            'куртк' => 'куртки',
            'штан' => 'штани',
            'футболк' => 'футболки',
            'жилет|розвантаж' => 'жилети',
            'бронеплас|плит' => 'бронеплати',
            'рукавиц|рукавич|перчатк' => 'рукавиці',
            'окуляр' => 'окуляри',
            'наколін|налокіт' => 'захист',
            'ремен|ремін|пояс' => 'ремені',
            'патч|шеврон|нашивк' => 'патчі/шеврони',
            'медик|аптечк|турнікет|бандаж|ifak' => 'медицина',
            'ліхтар' => 'ліхтарі',
            'ніж|мультитул' => 'ножі',
            'кепк|бейсболк|панам|шапк' => 'головні убори',
            'навушник|peltor|comtac|earmor|headset' => 'навушники',
            'термо|термобіл' => 'термобілизна',
            'флыс|фліс' => 'фліс',
            'софтшел|softshell' => 'софтшел',
        ];
    }

    /**
     * Clear cached patterns for a tenant.
     */
    public function clearCache(?int $tenantId = null): void
    {
        $cacheKey = "category_patterns_tenant_{$tenantId}";
        Cache::forget($cacheKey);
        
        Log::info('CategoryPatternService: cache cleared', ['tenant_id' => $tenantId]);
    }

    /**
     * Clear all cached patterns.
     */
    public function clearAllCache(): void
    {
        // Clear for known tenant IDs
        $tenantIds = Category::withoutGlobalScope(TenantScope::class)
            ->distinct()
            ->pluck('tenant_id')
            ->toArray();
        
        foreach ($tenantIds as $tenantId) {
            $this->clearCache($tenantId);
        }
        
        // Also clear null tenant
        $this->clearCache(null);
    }
}
