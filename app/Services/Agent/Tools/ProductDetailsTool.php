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
        
        return $orderedProducts->map(function ($product) {
            // Parse images from raw JSON
            $images = [];
            if ($product->raw && isset($product->raw['pictures'])) {
                $images = collect($product->raw['pictures'])
                    ->map(fn($pic) => $pic['url'] ?? null)
                    ->filter()
                    ->values()
                    ->toArray();
            } elseif ($product->images) {
                $images = $product->images;
            }
            
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
                'popularity' => $product->popularity,
                'ai_product_type' => $product->ai_product_type ?? '__unknown__',
                // Enriched fields for narratives (purely from stored data)
                'description' => ProductRawExtractor::description($raw, 'ua', $parentRaw),
                'characteristics' => ProductRawExtractor::attributes($raw, 'ua', $parentRaw),
            ];
        })->toArray();
    }
}
