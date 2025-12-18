<?php

namespace App\Services\Agent\Tools;

use App\Models\Product;
use Illuminate\Support\Facades\Log;

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
            
            return [
                'id' => $product->id,
                'article' => $product->article,
                'parent_article' => $product->parent_article,
                'title' => $title,
                'title_json' => $product->title_json,
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
            ];
        })->toArray();
    }
}
