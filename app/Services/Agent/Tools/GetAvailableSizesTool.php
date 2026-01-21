<?php

namespace App\Services\Agent\Tools;

use App\Models\Product;
use Illuminate\Support\Facades\Log;

/**
 * Tool for getting available sizes for a product.
 * 
 * Looks up all variants with the same parent_article to find available sizes.
 * Also provides size chart information for military/tactical clothing.
 */
class GetAvailableSizesTool
{
    /**
     * ECWCS/US Army size chart.
     * Format: Size => [height_cm_range, chest_cm_range, waist_cm_range, weight_kg_range]
     */
    public const ECWCS_SIZE_CHART = [
        'XS/XS' => ['height' => '152-157', 'chest' => '79-84', 'waist' => '61-66', 'weight' => '45-55'],
        'XS/S' => ['height' => '157-163', 'chest' => '79-84', 'waist' => '61-66', 'weight' => '50-58'],
        'S/XS' => ['height' => '152-157', 'chest' => '84-91', 'waist' => '66-74', 'weight' => '50-60'],
        'S/S' => ['height' => '157-163', 'chest' => '84-91', 'waist' => '66-74', 'weight' => '55-65'],
        'S/R' => ['height' => '165-175', 'chest' => '84-91', 'waist' => '66-74', 'weight' => '57-68'],
        'S/L' => ['height' => '175-185', 'chest' => '84-91', 'waist' => '66-74', 'weight' => '60-70'],
        'M/XS' => ['height' => '152-157', 'chest' => '91-99', 'waist' => '74-81', 'weight' => '60-70'],
        'M/S' => ['height' => '157-163', 'chest' => '91-99', 'waist' => '74-81', 'weight' => '63-75'],
        'M/R' => ['height' => '165-175', 'chest' => '91-99', 'waist' => '74-81', 'weight' => '68-80'],
        'M/L' => ['height' => '175-185', 'chest' => '91-99', 'waist' => '74-81', 'weight' => '72-85'],
        'L/XS' => ['height' => '152-157', 'chest' => '99-107', 'waist' => '81-89', 'weight' => '70-82'],
        'L/S' => ['height' => '157-163', 'chest' => '99-107', 'waist' => '81-89', 'weight' => '73-88'],
        'L/R' => ['height' => '165-175', 'chest' => '99-107', 'waist' => '81-89', 'weight' => '77-92'],
        'L/L' => ['height' => '175-185', 'chest' => '99-107', 'waist' => '81-89', 'weight' => '82-97'],
        'XL/XS' => ['height' => '152-157', 'chest' => '107-117', 'waist' => '89-99', 'weight' => '83-97'],
        'XL/S' => ['height' => '157-163', 'chest' => '107-117', 'waist' => '89-99', 'weight' => '88-103'],
        'XL/R' => ['height' => '165-175', 'chest' => '107-117', 'waist' => '89-99', 'weight' => '92-108'],
        'XL/L' => ['height' => '175-185', 'chest' => '107-117', 'waist' => '89-99', 'weight' => '97-115'],
        'XXL/R' => ['height' => '165-175', 'chest' => '117-127', 'waist' => '99-109', 'weight' => '105-125'],
        'XXL/L' => ['height' => '175-185', 'chest' => '117-127', 'waist' => '99-109', 'weight' => '110-130'],
    ];

    /**
     * Get available sizes for a product.
     * 
     * @param string $articleOrId Product article or ID
     * @param int|null $tenantId Tenant ID for filtering
     * @return array Available sizes with stock info and size chart
     */
    public function getSizes(string $articleOrId, ?int $tenantId = null): array
    {
        Log::info('GetAvailableSizesTool: looking up sizes', [
            'article_or_id' => $articleOrId,
            'tenant_id' => $tenantId,
        ]);

        // First, find the product
        $product = $this->findProduct($articleOrId, $tenantId);
        
        if (!$product) {
            return [
                'found' => false,
                'message' => 'Товар не знайдено',
            ];
        }

        // Get all variants by parent_article
        $parentArticle = $product->parent_article ?: $product->article;
        
        $query = Product::where(function ($q) use ($parentArticle, $product) {
            // Products with same parent_article
            $q->where('parent_article', $parentArticle)
              // Or the parent itself (if product IS the parent)
              ->orWhere('article', $parentArticle)
              // Or products where this article is their parent
              ->orWhere('parent_article', $product->article);
        })->where('in_stock', true);

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        $variants = $query->get(['id', 'article', 'title', 'size', 'color', 'price', 'in_stock', 'raw']);

        // Extract unique sizes
        $availableSizes = [];
        $colorsPerSize = [];
        
        foreach ($variants as $variant) {
            $size = $variant->size;
            
            // Try to extract from raw if not in DB
            if (!$size && $variant->raw) {
                $raw = is_array($variant->raw) ? $variant->raw : json_decode($variant->raw, true);
                $size = $raw['size'] ?? $raw['Size'] ?? $this->parseSizeFromTitle($variant->title);
            }
            
            if (!$size || $size === '-') {
                continue;
            }

            $color = $variant->color;
            if (!$color && $variant->raw) {
                $raw = is_array($variant->raw) ? $variant->raw : json_decode($variant->raw, true);
                $color = $raw['color'] ?? $raw['Color'] ?? null;
            }

            if (!isset($availableSizes[$size])) {
                $availableSizes[$size] = [
                    'size' => $size,
                    'in_stock' => true,
                    'price' => $variant->price,
                    'article' => $variant->article,
                    'product_id' => $variant->id,
                ];
            }

            // Collect colors for this size
            if ($color) {
                $colorsPerSize[$size][] = $color;
            }
        }

        // Add colors to sizes
        foreach ($availableSizes as $size => &$sizeInfo) {
            $sizeInfo['colors'] = array_values(array_unique($colorsPerSize[$size] ?? []));
        }

        // Sort sizes
        uksort($availableSizes, [$this, 'compareSizes']);

        // Detect product type and add size chart
        $sizeChart = $this->detectSizeChart($product);

        // Recommend size based on ECWCS chart
        $recommendation = null;

        return [
            'found' => true,
            'product' => [
                'id' => $product->id,
                'article' => $product->article,
                'title' => $product->title,
                'parent_article' => $parentArticle,
            ],
            'available_sizes' => array_values($availableSizes),
            'size_chart' => $sizeChart,
            'recommendation' => $recommendation,
            'note' => $this->getSizeNote($product),
        ];
    }

    /**
     * Recommend size based on customer measurements.
     * 
     * @param string $articleOrId Product article or ID
     * @param array $measurements Customer measurements [height, weight, chest, waist]
     * @param int|null $tenantId Tenant ID
     * @return array Size recommendation
     */
    public function recommendSize(string $articleOrId, array $measurements, ?int $tenantId = null): array
    {
        $product = $this->findProduct($articleOrId, $tenantId);
        
        if (!$product) {
            return ['found' => false, 'message' => 'Товар не знайдено'];
        }

        $height = $measurements['height'] ?? null;
        $weight = $measurements['weight'] ?? null;
        $chest = $measurements['chest'] ?? null;
        $waist = $measurements['waist'] ?? null;

        // Get available sizes first
        $sizesData = $this->getSizes($articleOrId, $tenantId);
        $availableSizes = collect($sizesData['available_sizes'] ?? []);

        if ($availableSizes->isEmpty()) {
            return [
                'found' => true,
                'recommendation' => null,
                'message' => 'Немає доступних розмірів для цього товару',
            ];
        }

        // Detect if this is ECWCS/military clothing
        $isEcwcs = $this->isEcwcsProduct($product);
        
        if ($isEcwcs) {
            $recommended = $this->recommendEcwcsSize($height, $weight, $chest, $waist, $availableSizes->pluck('size')->toArray());
            
            return [
                'found' => true,
                'product_title' => $product->title,
                'measurements' => [
                    'height' => $height,
                    'weight' => $weight,
                    'chest' => $chest,
                    'waist' => $waist,
                ],
                'recommendation' => $recommended,
                'available_sizes' => $sizesData['available_sizes'],
                'size_chart' => self::ECWCS_SIZE_CHART,
                'note' => 'Американський військовий крій, розмір позначається як ширина/зріст (напр. L/R = Large по ширині, Regular по зросту)',
            ];
        }

        // Generic sizing recommendation
        return [
            'found' => true,
            'product_title' => $product->title,
            'measurements' => $measurements,
            'recommendation' => null,
            'available_sizes' => $sizesData['available_sizes'],
            'note' => 'Для точного підбору розміру порівняйте свої заміри з розмірною сіткою товару',
        ];
    }

    /**
     * Find product by article or ID.
     */
    private function findProduct(string $articleOrId, ?int $tenantId = null): ?Product
    {
        $query = Product::query();

        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        // Try as ID first
        if (is_numeric($articleOrId)) {
            $product = $query->find($articleOrId);
            if ($product) {
                return $product;
            }
        }

        // Try as article
        return $query->where('article', $articleOrId)->first();
    }

    /**
     * Recommend ECWCS size based on measurements.
     */
    private function recommendEcwcsSize(?int $height, ?int $weight, ?int $chest, ?int $waist, array $availableSizes): ?array
    {
        $bestMatch = null;
        $bestScore = -1;

        foreach (self::ECWCS_SIZE_CHART as $size => $params) {
            // Skip if not available
            if (!in_array($size, $availableSizes)) {
                continue;
            }

            $score = 0;
            $matches = [];

            // Check height
            if ($height) {
                [$minH, $maxH] = array_map('intval', explode('-', $params['height']));
                if ($height >= $minH && $height <= $maxH) {
                    $score += 3;
                    $matches[] = 'зріст';
                } elseif ($height >= $minH - 5 && $height <= $maxH + 5) {
                    $score += 1;
                    $matches[] = 'зріст (близько)';
                }
            }

            // Check weight
            if ($weight) {
                [$minW, $maxW] = array_map('intval', explode('-', $params['weight']));
                if ($weight >= $minW && $weight <= $maxW) {
                    $score += 3;
                    $matches[] = 'вага';
                } elseif ($weight >= $minW - 5 && $weight <= $maxW + 10) {
                    $score += 1;
                    $matches[] = 'вага (близько)';
                }
            }

            // Check chest
            if ($chest) {
                [$minC, $maxC] = array_map('intval', explode('-', $params['chest']));
                if ($chest >= $minC && $chest <= $maxC) {
                    $score += 4;
                    $matches[] = 'груди';
                } elseif ($chest >= $minC - 3 && $chest <= $maxC + 5) {
                    $score += 2;
                    $matches[] = 'груди (близько)';
                }
            }

            // Check waist - most important for comfort!
            if ($waist) {
                [$minWst, $maxWst] = array_map('intval', explode('-', $params['waist']));
                if ($waist >= $minWst && $waist <= $maxWst) {
                    $score += 5;
                    $matches[] = 'талія';
                } elseif ($waist >= $minWst - 3 && $waist <= $maxWst + 5) {
                    $score += 2;
                    $matches[] = 'талія (близько)';
                } elseif ($waist > $maxWst + 5) {
                    // Waist too big - strongly penalize
                    $score -= 5;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = [
                    'size' => $size,
                    'score' => $score,
                    'matches' => $matches,
                    'chart' => $params,
                ];
            }
        }

        // If best score is low, recommend sizing up
        if ($bestMatch && $bestScore < 5) {
            $bestMatch['warning'] = 'Розміри на межі, рекомендуємо примірку або взяти більший';
        }

        return $bestMatch;
    }

    /**
     * Check if product is ECWCS military clothing.
     */
    private function isEcwcsProduct(Product $product): bool
    {
        $title = mb_strtolower($product->title ?? '');
        $category = mb_strtolower($product->category_path ?? '');
        
        $keywords = ['ecwcs', 'gen iii', 'gen-iii', 'level 7', 'level 5', 'level 1', 'us army', 'usa army', 'армії сша'];
        
        foreach ($keywords as $kw) {
            if (str_contains($title, $kw) || str_contains($category, $kw)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Detect appropriate size chart for product.
     */
    private function detectSizeChart(Product $product): ?array
    {
        if ($this->isEcwcsProduct($product)) {
            return [
                'type' => 'ECWCS/US Army',
                'note' => 'Позначення: Ширина/Зріст (напр. L/R = Large ширина, Regular зріст)',
                'legend' => [
                    'XS' => 'Extra-Small (дуже малий)',
                    'S' => 'Small (малий)',
                    'R' => 'Regular (середній зріст 165-175 см)',
                    'L' => 'Large / Long (великий / довгий зріст 175-185 см)',
                    'XL' => 'Extra-Large (дуже великий)',
                ],
                'chart' => self::ECWCS_SIZE_CHART,
            ];
        }
        
        return null;
    }

    /**
     * Get sizing note for product type.
     */
    private function getSizeNote(Product $product): ?string
    {
        if ($this->isEcwcsProduct($product)) {
            return 'Американський військовий крій. Перша літера — ширина (S/M/L/XL), друга — зріст (XS/S/R/L). Наприклад: L/R = Large по ширині, Regular по зросту (165-175 см). Якщо вага більша за вказану в таблиці — рекомендуємо брати більший розмір.';
        }
        
        return null;
    }

    /**
     * Parse size from product title.
     */
    private function parseSizeFromTitle(?string $title): ?string
    {
        if (!$title) {
            return null;
        }
        
        // Look for patterns like "L/R", "XL/L", "M/R", etc.
        if (preg_match('/\b(XXL|XL|L|M|S|XS)\/(XXL|XL|L|R|S|XS)\b/i', $title, $matches)) {
            return strtoupper($matches[0]);
        }
        
        // Look for simple sizes
        if (preg_match('/\b(XXL|XL|L|M|S|XS)\b/i', $title, $matches)) {
            return strtoupper($matches[1]);
        }
        
        return null;
    }

    /**
     * Compare sizes for sorting.
     */
    private function compareSizes(string $a, string $b): int
    {
        $order = ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL'];
        
        // Extract width part (first letter)
        $aWidth = explode('/', $a)[0] ?? $a;
        $bWidth = explode('/', $b)[0] ?? $b;
        
        $aIdx = array_search($aWidth, $order);
        $bIdx = array_search($bWidth, $order);
        
        if ($aIdx === false) $aIdx = 99;
        if ($bIdx === false) $bIdx = 99;
        
        if ($aIdx !== $bIdx) {
            return $aIdx - $bIdx;
        }
        
        // Same width, compare by height
        $heightOrder = ['XS', 'S', 'R', 'L', 'XL'];
        $aHeight = explode('/', $a)[1] ?? '';
        $bHeight = explode('/', $b)[1] ?? '';
        
        $aHIdx = array_search($aHeight, $heightOrder);
        $bHIdx = array_search($bHeight, $heightOrder);
        
        if ($aHIdx === false) $aHIdx = 99;
        if ($bHIdx === false) $bHIdx = 99;
        
        return $aHIdx - $bHIdx;
    }
}
