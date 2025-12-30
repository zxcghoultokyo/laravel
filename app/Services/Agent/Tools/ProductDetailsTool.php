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
     */
    public function getCards(array $ids, int $limit = 10): array
    {
        if (empty($ids)) {
            return [];
        }
        
        $ids = array_slice($ids, 0, $limit);
        
        Log::info('ProductDetailsTool: fetching cards', ['ids' => $ids]);
        
        $products = Product::whereIn('id', $ids)
            ->where('in_stock', true)
            ->get();

        // Prefetch parent raw payloads to reuse for description/characteristics fallbacks
        $parentArticles = $products->pluck('parent_article')->filter()->unique()->all();
        $parentRawMap = [];
        if ($parentArticles) {
            Product::query()
                ->whereIn('article', $parentArticles)
                ->get(['article', 'raw'])
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
        
        // Collect all sibling variants for size switching
        $allParentArticles = $orderedProducts->pluck('parent_article')->filter()->unique()->all();
        $sizeVariantsMap = [];
        if ($allParentArticles) {
            // Get all products with the same parent_article (siblings)
            $allSiblings = Product::query()
                ->whereIn('parent_article', $allParentArticles)
                ->where('in_stock', true)
                ->get(['id', 'article', 'parent_article', 'link', 'raw', 'title', 'size']);
            
            foreach ($allSiblings as $sibling) {
                $parentArt = $sibling->parent_article;
                
                // Prefer DB size field, then try raw, then parse from title
                $size = $sibling->size;
                if (!$size) {
                    $sibRaw = is_array($sibling->raw ?? null) ? $sibling->raw : (array) ($sibling->raw ?? []);
                    $size = $this->extractSize($sibRaw);
                }
                if (!$size && $sibling->title) {
                    $size = $this->parseSizeFromTitle($sibling->title);
                }
                
                if ($size) {
                    if (!isset($sizeVariantsMap[$parentArt])) {
                        $sizeVariantsMap[$parentArt] = [];
                    }
                    $sizeVariantsMap[$parentArt][] = [
                        'id' => $sibling->id,
                        'article' => $sibling->article,
                        'size' => $size,
                        'link' => $sibling->link,
                    ];
                }
            }
            
            // Sort variants by size order
            $sizeOrder = ['XXS', 'XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL', '3XL', '4XL', '5XL'];
            foreach ($sizeVariantsMap as $parentArt => &$variants) {
                usort($variants, function ($a, $b) use ($sizeOrder) {
                    $posA = array_search(strtoupper($a['size']), $sizeOrder);
                    $posB = array_search(strtoupper($b['size']), $sizeOrder);
                    if ($posA === false) $posA = 999;
                    if ($posB === false) $posB = 999;
                    return $posA - $posB ?: strcmp($a['size'], $b['size']);
                });
            }
        }
        
        return $orderedProducts->map(function ($product) use ($parentRawMap, $sizeVariantsMap) {
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
            $currentSize = $product->size;
            if (!$currentSize) {
                $currentSize = $this->extractSize($raw);
            }
            if (!$currentSize) {
                $currentSize = $this->parseSizeFromTitle($title);
            }
            
            // Get size variants (siblings with same parent_article)
            $sizeVariants = [];
            if ($parentArticle && isset($sizeVariantsMap[$parentArticle])) {
                $sizeVariants = $sizeVariantsMap[$parentArticle];
            }

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
                'color' => $product->color,
                'size' => $currentSize,
                'size_variants' => $sizeVariants,
                'popularity' => $product->popularity,
                'ai_product_type' => $product->ai_product_type ?? '__unknown__',
                // Enriched fields for narratives (purely from stored data)
                'description' => ProductRawExtractor::description($raw, 'ua', $parentRaw),
                'characteristics' => ProductRawExtractor::attributes($raw, 'ua', $parentRaw),
            ];
        })->toArray();
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
     * Extract size from raw product data.
     * Horoshop stores size in various places: Rozmir (top-level), select.size, params.size, characteristics.size
     */
    protected function extractSize(array $raw): ?string
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
        
        // 7. Try mod_title (Horoshop modification title)
        if (!empty($raw['mod_title'])) {
            $modTitle = is_array($raw['mod_title']) 
                ? ($raw['mod_title']['ua'] ?? $raw['mod_title']['ru'] ?? reset($raw['mod_title']))
                : $raw['mod_title'];
            if (is_string($modTitle) && trim($modTitle) !== '') {
                return trim($modTitle);
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
}
