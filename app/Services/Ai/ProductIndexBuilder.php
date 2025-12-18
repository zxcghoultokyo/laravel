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
            /** @var \App\Services\Ai\AiClient $ai */
            $ai = app(AiClient::class);

            $system = implode("\n", [
                'You are an AI enrichment service for a tactical e-commerce catalog.',
                'Return a STRICT JSON object with keys:',
                'product_type: short snake_case type (e.g., helmet, plate_carrier, armor_plate, tshirt)',
                'ai_category: broad category (e.g., helmets, armor, apparel, accessories)',
                'materials: comma-separated materials if known',
                'standards: comma-separated standards if known (e.g., NIJ III+)',
                'slang: comma-separated slang names',
                'keywords: comma-separated search keywords',
                'usage: short description of typical use',
                'embedding: optional vector array (omit if unavailable).',
                'If unsure, leave fields null but NEVER hallucinate.',
            ]);

            $payload = [
                'title'         => (string) ($product->title ?? ''),
                'category_path' => (string) ($product->category_path ?? ''),
                'raw'           => $product->raw ?? null,
                'color'         => (string) ($product->color ?? ''),
                'brand'         => (string) ($product->brand ?? ''),
                'search_index'  => (string) ($product->search_index ?? ''),
            ];

            $aiData = $ai->chatJson($system, $payload, ['temperature' => 0.1]);
        } catch (\Throwable $e) {
            Log::warning('ProductIndexBuilder::buildForProduct AI error: ' . $e->getMessage());
        }

        // fallback, якщо AI нічого не віддав
        $defaults = $this->fallbackFromProduct($product);

        $payload = array_merge($defaults, is_array($aiData) ? $aiData : []);

        // Забезпечити наявність raw_ai_json для аудиту/дебагу
        $rawJson = null;
        try {
            $rawJson = json_encode($aiData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\Throwable $e) {
            $rawJson = null;
        }

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
                'raw_ai_json'  => $rawJson,
            ]
        );
    }

    /**
     * Дуже простий fallback: щось витягуємо з самого продукту,
     * щоб запис не був пустим навіть без AI.
     */
    protected function fallbackFromProduct(Product $product): array
    {
        $category = (string) ($product->category_path ?? '');
        $title    = (string) ($product->title ?? '');

        // Rule-based mapping: базове покриття без LLM
        $mapType = $this->mapTypeFromCategory($category);

        return [
            'product_type' => $mapType,
            'ai_category'  => $this->mapAiCategoryFromType($mapType),
            'materials'    => null,
            'standards'    => null,
            'slang'        => null,
            'keywords'     => trim($title . ' ' . $category),
            'usage'        => null,
            'embedding'    => null,
        ];
    }

    protected function mapTypeFromCategory(?string $categoryPath): ?string
    {
        if (! $categoryPath) {
            return null;
        }
        $q = mb_strtolower($categoryPath);

        // UA/RU keywords for core mappings
        if (mb_stripos($q, 'шолом') !== false || mb_stripos($q, 'каска') !== false || mb_stripos($q, 'шоломи') !== false) {
            return 'helmet';
        }
        if (mb_stripos($q, 'бронепластин') !== false || mb_stripos($q, 'плита') !== false || mb_stripos($q, 'плити') !== false) {
            return 'armor_plate';
        }
        if (mb_stripos($q, 'плитоноск') !== false) {
            return 'plate_carrier';
        }
        if (mb_stripos($q, 'футболк') !== false || mb_stripos($q, 'футболка') !== false) {
            return 'tshirt';
        }
        return null;
    }

    protected function mapAiCategoryFromType(?string $type): ?string
    {
        return match ($type) {
            'helmet'        => 'helmets',
            'armor_plate'   => 'armor',
            'plate_carrier' => 'armor',
            'tshirt'        => 'apparel',
            default         => null,
        };
    }
}
