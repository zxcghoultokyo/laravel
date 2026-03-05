<?php

namespace App\Services\Rozetka;

use App\Models\RozetkaCategoryAttribute;
use Illuminate\Support\Facades\Log;

class RozetkaAttributeService
{
    public function __construct(protected RozetkaClient $client) {}

    /**
     * Fetch and cache attributes for a Rozetka category.
     * Uses /v1/market-categories/category-options endpoint.
     */
    public function syncCategoryAttributes(int $rozetkaCategoryId): int
    {
        $response = $this->client->get('/v1/market-categories/category-options', [
            'category_id' => $rozetkaCategoryId,
        ]);

        $options = $response['data'] ?? $response['content'] ?? $response;

        if (is_string($options)) {
            $options = json_decode($options, true) ?? [];
        }

        if (! is_array($options)) {
            Log::warning("Rozetka: unexpected response for category {$rozetkaCategoryId} attributes");

            return 0;
        }

        // Group options by attribute_id to collect all values
        $grouped = [];
        foreach ($options as $opt) {
            $attrId = $opt['id'] ?? null;
            if (! $attrId) {
                continue;
            }

            if (! isset($grouped[$attrId])) {
                $grouped[$attrId] = [
                    'attribute_id' => $attrId,
                    'name' => $opt['name'] ?? '',
                    'attr_type' => $opt['attr_type'] ?? 'TextInput',
                    'filter_type' => $opt['filter_type'] ?? 'disable',
                    'unit' => $opt['unit'] ?? null,
                    'is_global' => $opt['is_global'] ?? false,
                    'values' => [],
                ];
            }

            if (($opt['value_id'] ?? null) !== null) {
                $grouped[$attrId]['values'][] = [
                    'id' => $opt['value_id'],
                    'name' => $opt['value_name'] ?? '',
                ];
            }
        }

        $synced = 0;
        foreach ($grouped as $attr) {
            RozetkaCategoryAttribute::updateOrCreate(
                [
                    'rozetka_category_id' => $rozetkaCategoryId,
                    'attribute_id' => $attr['attribute_id'],
                ],
                [
                    'name' => $attr['name'],
                    'attr_type' => $attr['attr_type'],
                    'filter_type' => $attr['filter_type'],
                    'unit' => $attr['unit'],
                    'is_global' => $attr['is_global'],
                    'values' => ! empty($attr['values']) ? $attr['values'] : null,
                ]
            );
            $synced++;
        }

        Log::info("Rozetka: synced {$synced} attributes for category {$rozetkaCategoryId}");

        return $synced;
    }

    /**
     * Get attributes for a category, fetch if not cached.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, RozetkaCategoryAttribute>
     */
    public function getAttributesForCategory(int $rozetkaCategoryId): \Illuminate\Database\Eloquent\Collection
    {
        $attrs = RozetkaCategoryAttribute::where('rozetka_category_id', $rozetkaCategoryId)->get();

        if ($attrs->isEmpty()) {
            $this->syncCategoryAttributes($rozetkaCategoryId);
            $attrs = RozetkaCategoryAttribute::where('rozetka_category_id', $rozetkaCategoryId)->get();
        }

        return $attrs;
    }
}
