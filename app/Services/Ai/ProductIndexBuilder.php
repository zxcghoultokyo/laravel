<?php

namespace App\Services\Ai;

use App\Models\Product;
use App\Models\ProductAiIndex;
use Illuminate\Support\Facades\Log;

class ProductIndexBuilder
{
    public function buildForProduct(Product $product): ProductAiIndex
    {
        $aiData = [];

        try {
            /** @var \App\Services\Ai\AiRouter $aiRouter */
            $aiRouter = app(AiRouter::class);

            // ТИМЧАСОВО: метод треба буде реалізувати/допилити в AiRouter
            // щоб він повертав масив типу:
            // [
            //   'product_type'   => 'helmet',
            //   'ai_category'    => 'armor',
            //   'materials'      => 'uhmwpe, aramid',
            //   'standards'      => 'NIJ III+',
            //   'slang'          => 'каска, шолом, helmet',
            //   'keywords'       => 'ballistic helmet, tactical helmet',
            //   'usage'          => 'frontline infantry, police SWAT',
            //   'embedding'      => [ ... ]
            // ]
            $aiData = $aiRouter->buildProductIndexData($product);
        } catch (\Throwable $e) {
            Log::warning('ProductIndexBuilder::buildForProduct AI error: ' . $e->getMessage());
        }

        // fallback, якщо AI нічого не віддав
        $defaults = $this->fallbackFromProduct($product);

        $payload = array_merge($defaults, $aiData ?? []);

        return ProductAiIndex::updateOrCreate(
            ['product_id' => $product->id],
            [
                'product_type' => $payload['product_type'] ?? null,
                'ai_category'  => $payload['ai_category'] ?? null,
                'materials'    => $payload['materials'] ?? null,
                'standards'    => $payload['standards'] ?? null,
                'slang'        => $payload['slang'] ?? null,
                'keywords'     => $payload['keywords'] ?? null,
                'usage'        => $payload['usage'] ?? null,
                'embedding'    => $payload['embedding'] ?? null,
            ]
        );
    }

    /**
     * Дуже простий fallback: щось витягуємо з самого продукту,
     * щоб запис не був пустим навіть без AI.
     */
    protected function fallbackFromProduct(Product $product): array
    {
        $category = $product->category_path ?? '';
        $title    = $product->title ?? '';
        $search   = $product->search_index ?? '';

        return [
            // Типово можемо брати верхній рівень категорії як product_type,
            // далі AI це перепише.
            'product_type' => $this->guessProductTypeFromCategory($category),
            'ai_category'  => null,
            'materials'    => null,
            'standards'    => null,
            'slang'        => null,
            'keywords'     => trim($title . ' ' . $category),
            'usage'        => null,
            'embedding'    => null,
        ];
    }

    protected function guessProductTypeFromCategory(?string $categoryPath): ?string
    {
        if (! $categoryPath) {
            return null;
        }

        $parts = explode('/', $categoryPath);
        $parts = array_map('trim', $parts);
        $parts = array_filter($parts);

        if (empty($parts)) {
            return null;
        }

        // Просто беремо останню частину як "type" (Плитоноски, Шоломи, Футболки і т.д.)
        return mb_strtolower(end($parts));
    }
}
