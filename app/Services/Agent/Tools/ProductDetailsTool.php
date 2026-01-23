<?php

namespace App\Services\Agent\Tools;

use App\Models\Product;
use Illuminate\Support\Facades\Log;
use App\Support\ProductRawExtractor;

class ProductDetailsTool
{
    /**
     * Get full product cards for display
     * Returns array of product cards ready for widget
     * 
     * @param array $ids Product IDs to fetch
     * @param int $limit Max number of cards
     * @param int|null $tenantId Filter by tenant (to avoid mixing variants from different stores)
     */
    public function getCards(array $ids, int $limit = 10, ?int $tenantId = null): array
    {
        if (empty($ids)) {
            return [];
        }
        
        $ids = array_slice($ids, 0, $limit);
        
        Log::info('ProductDetailsTool: fetching cards', ['ids' => $ids, 'tenant_id' => $tenantId]);
        
        $query = Product::whereIn('id', $ids)->where('in_stock', true);
        
        // Filter by tenant if specified (important for multi-tenant)
        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }
        
        $products = $query->get();
        
        // Get tenant_id from first product for consistency in variant fetching
        $productTenantId = $tenantId ?? $products->first()?->tenant_id;

        // Prefetch parent raw payloads to reuse for description/characteristics fallbacks
        $parentArticles = $products->pluck('parent_article')->filter()->unique()->all();
        $parentRawMap = [];
        if ($parentArticles) {
            $parentQuery = Product::query()->whereIn('article', $parentArticles);
            if ($productTenantId) {
                $parentQuery->where('tenant_id', $productTenantId);
            }
            $parentQuery->get(['article', 'raw'])
                ->each(function ($parent) use (&$parentRawMap) {
                    $parentRawMap[$parent->article] = is_array($parent->raw ?? null)
                        ? $parent->raw
                        : (array) ($parent->raw ?? []);
                });
        }
        
        // Maintain order from $ids
        $productsById = $products->keyBy('id');
        $orderedProducts = collect($ids)
            ->map(fn($id) => $productsById->get($id))
            ->filter()
            ->values();
        
        // Collect all sibling variants for size/color switching
        $allParentArticles = $orderedProducts->pluck('parent_article')->filter()->unique()->all();
        $variantsMap = [];
        if ($allParentArticles) {
            // Get all products with the same parent_article (siblings) - FILTER BY TENANT!
            $siblingsQuery = Product::query()
                ->whereIn('parent_article', $allParentArticles)
                ->where('in_stock', true);
            
            // Important: filter siblings by same tenant to avoid mixing variants from different stores
            if ($productTenantId) {
                $siblingsQuery->where('tenant_id', $productTenantId);
            }
            
            $allSiblings = $siblingsQuery->get(['id', 'article', 'parent_article', 'link', 'raw', 'title', 'size', 'color']);
            
            foreach ($allSiblings as $sibling) {
                $parentArt = $sibling->parent_article;
                
                // Prefer DB size field, then try raw, then parse from title
                // But validate that DB size looks like a real size (not title)
                $size = $sibling->size;
                if ($size && !$this->isValidSize($size, $sibling->title)) {
                    $size = null; // DB size is probably title, not actual size
                }
                if (!$size) {
                    $sibRaw = is_array($sibling->raw ?? null) ? $sibling->raw : (array) ($sibling->raw ?? []);
                    $size = $this->extractSize($sibRaw, $sibling->title);
                }
                if (!$size && $sibling->title) {
                    $size = $this->parseSizeFromTitle($sibling->title);
                }
                
                // Get color from DB or raw
                $color = $sibling->color;
                if (!$color) {
                    $sibRaw = is_array($sibling->raw ?? null) ? $sibling->raw : (array) ($sibling->raw ?? []);
                    $color = $this->extractColor($sibRaw);
                }
                
                if (!isset($variantsMap[$parentArt])) {
                    $variantsMap[$parentArt] = [];
                }
                $variantsMap[$parentArt][] = [
                    'id' => $sibling->id,
                    'article' => $sibling->article,
                    'size' => $size,
                    'color' => $color,
                    'link' => $sibling->link,
                ];
            }
            
            // Sort variants by color then size
            $sizeOrder = ['XXS', 'XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL', '3XL', '4XL', '5XL'];
            foreach ($variantsMap as $parentArt => &$variants) {
                usort($variants, function ($a, $b) use ($sizeOrder) {
                    // First sort by color
                    $colorCmp = strcmp($a['color'] ?? '', $b['color'] ?? '');
                    if ($colorCmp !== 0) return $colorCmp;
                    
                    // Then by size
                    $posA = array_search(strtoupper($a['size'] ?? ''), $sizeOrder);
                    $posB = array_search(strtoupper($b['size'] ?? ''), $sizeOrder);
                    if ($posA === false) $posA = 999;
                    if ($posB === false) $posB = 999;
                    return $posA - $posB ?: strcmp($a['size'] ?? '', $b['size'] ?? '');
                });
            }
        }
        
        return $orderedProducts->map(function ($product) use ($parentRawMap, $variantsMap) {
            // Parse images from raw JSON or images field
            $images = $this->extractImages($product);
            
            // Get title in Ukrainian
            $title = $product->title;
            if ($product->title_json && isset($product->title_json['ua'])) {
                $title = $product->title_json['ua'];
            }
            
            $raw = is_array($product->raw ?? null) ? $product->raw : (array) ($product->raw ?? []);
            $parentRaw = [];
            $parentArticle = (string) ($product->parent_article ?? '');
            if ($parentArticle !== '' && isset($parentRawMap[$parentArticle])) {
                $parentRaw = $parentRawMap[$parentArticle];
            }
            
            // Extract size: prefer DB field, then try raw fields, then parse from title
            // But validate that DB size looks like a real size (not title)
            $currentSize = $product->size;
            if ($currentSize && !$this->isValidSize($currentSize, $title)) {
                $currentSize = null; // DB size is probably title, not actual size
            }
            if (!$currentSize) {
                $currentSize = $this->extractSize($raw, $title);
            }
            if (!$currentSize) {
                $currentSize = $this->parseSizeFromTitle($title);
            }
            
            // Extract color: prefer DB field, then try raw
            $currentColor = $product->color;
            if (!$currentColor) {
                $currentColor = $this->extractColor($raw);
            }
            
            // Get all variants (siblings with same parent_article) - includes color and size
            $allVariants = [];
            if ($parentArticle && isset($variantsMap[$parentArticle])) {
                $allVariants = $variantsMap[$parentArticle];
            }
            
            // Build structured variants: grouped by color with sizes array
            $colorVariants = $this->buildColorVariants($allVariants, $currentColor);
            
            // Keep backward-compatible size_variants (flat list)
            $sizeVariants = array_map(function($v) {
                return [
                    'id' => $v['id'],
                    'article' => $v['article'],
                    'size' => $v['size'],
                    'link' => $v['link'],
                ];
            }, $allVariants);

            return [
                'id' => $product->id,
                'article' => $product->article,
                'parent_article' => $product->parent_article,
                'title' => $title,
                'title_json' => $product->title_json,
                'brand' => $product->brand,
                'price' => $product->price,
                'price_old' => $product->price_old,
                'category_path' => $product->category_path,
                'slug' => $product->slug,
                'link' => $product->link,
                'images' => $images,
                'presence' => $product->presence,
                'quantity' => $product->quantity,
                'in_stock' => $product->in_stock,
                'color' => $currentColor,
                'size' => $currentSize,
                'size_variants' => $sizeVariants,
                'color_variants' => $colorVariants,
                'popularity' => $product->popularity,
                'ai_product_type' => $product->ai_product_type ?? '__unknown__',
                // Enriched fields for narratives (purely from stored data)
                'description' => ProductRawExtractor::description($raw, 'ua', $parentRaw),
                'characteristics' => ProductRawExtractor::attributes($raw, 'ua', $parentRaw),
            ];
        })->toArray();
    }
    
    /**
     * Build color variants structure: array of colors with their available sizes.
     */
    protected function buildColorVariants(array $allVariants, ?string $currentColor): array
    {
        $byColor = [];
        
        foreach ($allVariants as $variant) {
            $color = $variant['color'] ?? null;
            if (!$color) continue;
            
            if (!isset($byColor[$color])) {
                $byColor[$color] = [
                    'color' => $color,
                    'is_current' => ($color === $currentColor),
                    'sizes' => [],
                ];
            }
            
            if ($variant['size']) {
                $byColor[$color]['sizes'][] = [
                    'id' => $variant['id'],
                    'article' => $variant['article'],
                    'size' => $variant['size'],
                    'link' => $variant['link'],
                ];
            }
        }
        
        return array_values($byColor);
    }

    /**
     * Extract images from product with multiple fallbacks.
     */
    protected function extractImages(Product $product): array
    {
        $images = [];

        // 1. Try raw['pictures'] first (Horoshop standard structure)
        if ($product->raw && is_array($product->raw) && !empty($product->raw['pictures'])) {
            $images = collect($product->raw['pictures'])
                ->map(fn($pic) => is_array($pic) ? ($pic['url'] ?? null) : $pic)
                ->filter()
                ->values()
                ->toArray();
        }

        // 2. Try raw['images'] (alternative structure)
        if (empty($images) && $product->raw && is_array($product->raw) && !empty($product->raw['images'])) {
            $imgs = $product->raw['images'];
            if (is_array($imgs)) {
                $images = collect($imgs)
                    ->map(fn($img) => is_array($img) ? ($img['url'] ?? $img['src'] ?? null) : $img)
                    ->filter()
                    ->values()
                    ->toArray();
            }
        }

        // 3. Fallback to images field (from DB column)
        if (empty($images) && $product->images) {
            $imgs = $product->images;
            // Handle if it's a string (shouldn't be, but just in case)
            if (is_string($imgs)) {
                $decoded = json_decode($imgs, true);
                $imgs = is_array($decoded) ? $decoded : [$imgs];
            }
            if (is_array($imgs)) {
                $images = array_values(array_filter($imgs));
            }
        }

        // 3. Fallback to raw['image'] (single image)
        if (empty($images) && $product->raw && is_array($product->raw) && !empty($product->raw['image'])) {
            $images = [$product->raw['image']];
        }

        // 4. Fallback to raw['main_image']
        if (empty($images) && $product->raw && is_array($product->raw) && !empty($product->raw['main_image'])) {
            $images = [$product->raw['main_image']];
        }

        // Normalize URLs (encode special chars if needed)
        $images = array_map(function ($url) {
            if (!is_string($url)) return null;
            // URL encode only the path portion if it has problematic chars
            // But don't double-encode already encoded URLs
            return $url;
        }, $images);

        return array_values(array_filter($images));
    }
    
    /**
     * Extract color from raw product data.
     * Horoshop stores color in: Kolir (top-level custom attr), color (standard)
     */
    protected function extractColor(array $raw): ?string
    {
        // 1. Try Kolir at top level (Horoshop custom attribute - most specific!)
        // Format: {"id": 9, "value": {"ua": "Мультикам"}}
        if (!empty($raw['Kolir']['value'])) {
            $kolir = $raw['Kolir']['value'];
            $color = is_array($kolir) 
                ? ($kolir['ua'] ?? $kolir['ru'] ?? reset($kolir))
                : $kolir;
            if (is_string($color) && trim($color) !== '') {
                return trim($color);
            }
        }
        
        // 2. Try standard color field
        // Format: {"id": 33, "value": {"ua": "Хаки"}}
        if (!empty($raw['color']['value'])) {
            $colorData = $raw['color']['value'];
            $color = is_array($colorData) 
                ? ($colorData['ua'] ?? $colorData['ru'] ?? reset($colorData))
                : $colorData;
            if (is_string($color) && trim($color) !== '') {
                return trim($color);
            }
        }
        
        // 3. Try direct color as string
        if (!empty($raw['color']) && is_string($raw['color'])) {
            return trim($raw['color']);
        }
        
        return null;
    }
    
    /**
     * Extract size from raw product data.
     * Horoshop stores size in various places: Rozmir (top-level), select.size, params.size, characteristics.size
     */
    protected function extractSize(array $raw, ?string $title = null): ?string
    {
        // 1. Try Rozmir at top level (Horoshop custom attribute - most common!)
        // Format: {"id": 29, "value": {"ua": "S/S"}}
        if (!empty($raw['Rozmir']['value'])) {
            $rozmir = $raw['Rozmir']['value'];
            $size = is_array($rozmir) 
                ? ($rozmir['ua'] ?? $rozmir['ru'] ?? reset($rozmir))
                : $rozmir;
            if (is_string($size) && trim($size) !== '') {
                return trim($size);
            }
        }
        
        // 1b. Try rozmir with lowercase (some Horoshop stores use lowercase)
        if (!empty($raw['rozmir']['value'])) {
            $rozmir = $raw['rozmir']['value'];
            $size = is_array($rozmir) 
                ? ($rozmir['ua'] ?? $rozmir['ru'] ?? reset($rozmir))
                : $rozmir;
            if (is_string($size) && trim($size) !== '') {
                return trim($size);
            }
        }
        
        // 2. Try select.size (most common for variants)
        if (!empty($raw['select']['size'])) {
            $size = $raw['select']['size'];
            if (is_array($size)) {
                // May be {ua: 'M', ru: 'M'}
                $size = $size['ua'] ?? $size['ru'] ?? reset($size);
            }
            return is_string($size) ? trim($size) : null;
        }
        
        // 3. Try select.rozmir (Ukrainian)
        if (!empty($raw['select']['rozmir'])) {
            $size = $raw['select']['rozmir'];
            if (is_array($size)) {
                $size = $size['ua'] ?? $size['ru'] ?? reset($size);
            }
            return is_string($size) ? trim($size) : null;
        }
        
        // 4. Try params.size
        if (!empty($raw['params']['size'])) {
            return is_string($raw['params']['size']) ? trim($raw['params']['size']) : null;
        }
        
        // 5. Try characteristics.size
        if (!empty($raw['characteristics']['size'])) {
            $size = $raw['characteristics']['size'];
            if (is_array($size)) {
                $size = $size['value'] ?? $size['ua'] ?? $size['ru'] ?? reset($size);
            }
            return is_string($size) ? trim((string) $size) : null;
        }
        
        // 6. Try characteristics.rozmir
        if (!empty($raw['characteristics']['rozmir'])) {
            $size = $raw['characteristics']['rozmir'];
            if (is_array($size)) {
                $size = $size['value'] ?? $size['ua'] ?? $size['ru'] ?? reset($size);
            }
            return is_string($size) ? trim((string) $size) : null;
        }
        
        // 6. Try direct size field
        if (!empty($raw['size'])) {
            return is_string($raw['size']) ? trim($raw['size']) : null;
        }
        
        // 7. Try mod_title (Horoshop modification title) - but ONLY if different from product title
        // Some stores set mod_title = title when there's no actual modification
        if (!empty($raw['mod_title'])) {
            $modTitle = is_array($raw['mod_title']) 
                ? ($raw['mod_title']['ua'] ?? $raw['mod_title']['ru'] ?? reset($raw['mod_title']))
                : $raw['mod_title'];
            if (is_string($modTitle) && trim($modTitle) !== '') {
                $modTitleTrimmed = trim($modTitle);
                // Skip if mod_title equals the product title (not a real size/variant)
                if ($title && mb_strtolower($modTitleTrimmed) === mb_strtolower(trim($title))) {
                    // mod_title is same as title, not useful as size
                } else {
                    // Additional check: if mod_title is longer than 20 chars, it's probably not a size
                    if (mb_strlen($modTitleTrimmed) <= 20) {
                        return $modTitleTrimmed;
                    }
                }
            }
        }
        
        return null;
    }
    
    /**
     * Parse size from product title.
     * Common patterns: "... Бежевий L", "... Multicam USA XL", "... US L-Long"
     */
    protected function parseSizeFromTitle(string $title): ?string
    {
        // Pattern for common sizes at the end of title
        // Matches: S, M, L, XL, XXL, 3XL, XS, S/L, M/R, L-Long, US M-Regular, etc.
        $patterns = [
            // Size with length: "L-Long", "M-Regular", "S-Short"
            '/\b(XXS|XS|S|M|L|XL|XXL|XXXL|2XL|3XL|4XL|5XL)[-\/](Long|Regular|Short|L|R|S)\b/i',
            // US size format: "US L-Long", "US M"
            '/\bUS\s+(XXS|XS|S|M|L|XL|XXL|XXXL|2XL|3XL|4XL|5XL)(?:[-\/](Long|Regular|Short|L|R|S))?\b/i',
            // Simple size at end: "... XL", "... M"
            '/\s(XXS|XS|S|M|L|XL|XXL|XXXL|2XL|3XL|4XL|5XL)\s*$/i',
            // Numeric sizes (shoes, etc): "43", "44.5"
            '/\s(\d{2}(?:\.\d)?)\s*$/',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $title, $matches)) {
                // Return full match for compound sizes, or first capture group
                if (isset($matches[2]) && !empty($matches[2])) {
                    return strtoupper($matches[1]) . '/' . strtoupper($matches[2]);
                }
                return strtoupper($matches[1]);
            }
        }
        
        return null;
    }
    
    /**
     * Check if size value is a valid size (not just a product title).
     * Returns false if size looks like it's actually the product title.
     */
    protected function isValidSize(?string $size, ?string $title): bool
    {
        if (!$size) {
            return false;
        }
        
        // If size equals title (case insensitive), it's not a real size
        if ($title && mb_strtolower(trim($size)) === mb_strtolower(trim($title))) {
            return false;
        }
        
        // If size is too long (>20 chars), probably not a real size
        if (mb_strlen($size) > 20) {
            return false;
        }
        
        // Valid sizes usually match these patterns
        $validPatterns = [
            '/^(XXS|XS|S|M|L|XL|XXL|XXXL|2XL|3XL|4XL|5XL)$/i',      // Simple sizes
            '/^(XXS|XS|S|M|L|XL|XXL|XXXL|2XL|3XL|4XL|5XL)[-\/]/i',   // Compound sizes
            '/^\d{2}(\.\d)?$/',                                       // Numeric (shoes)
            '/^US\s/i',                                               // US format
            '/^EU\s/i',                                               // EU format
            '/^[SMLX]{1,4}[-\/][SMLR]$/i',                           // S/L, M/R patterns
        ];
        
        foreach ($validPatterns as $pattern) {
            if (preg_match($pattern, trim($size))) {
                return true;
            }
        }
        
        // If none of the patterns match but it's short, still might be valid
        return mb_strlen($size) <= 10;
    }
}
