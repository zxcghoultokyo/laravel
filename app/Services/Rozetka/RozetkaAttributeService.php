<?php

namespace App\Services\Rozetka;

use App\Models\RozetkaCategoryAttribute;
use Illuminate\Support\Facades\Log;

class RozetkaAttributeService
{
    public function __construct(protected RozetkaClient $client) {}

    /**
     * Fetch and cache attributes for a Rozetka category.
     * Uses /items-create/attributes endpoint (paginated, 5 per page).
     * Falls back to /v1/market-categories/category-options if needed.
     */
    public function syncCategoryAttributes(int $rozetkaCategoryId): int
    {
        $allAttributes = $this->fetchAllAttributes($rozetkaCategoryId);

        if (empty($allAttributes)) {
            return $this->syncCategoryAttributesLegacy($rozetkaCategoryId);
        }

        $synced = 0;
        foreach ($allAttributes as $attr) {
            $attrId = $attr['id'] ?? null;
            if (! $attrId) {
                continue;
            }

            // Fetch values for list-type attributes
            $values = null;
            $type = $attr['type'] ?? 'TextInput';
            if (in_array($type, ['ComboBox', 'List', 'ListValues', 'CheckBoxGroup', 'CheckBoxGroupValues'])) {
                $values = $this->fetchAttributeValues($rozetkaCategoryId, $attrId);
            }

            RozetkaCategoryAttribute::updateOrCreate(
                [
                    'rozetka_category_id' => $rozetkaCategoryId,
                    'attribute_id' => $attrId,
                ],
                [
                    'name' => $attr['title_ua'] ?? $attr['title'] ?? '',
                    'attr_type' => $type,
                    'filter_type' => ($attr['is_filter'] ?? false) ? 'main' : 'disable',
                    'unit' => $attr['unit_ua'] ?? $attr['unit'] ?? null,
                    'is_global' => true,
                    'values' => $values,
                ]
            );
            $synced++;
        }

        Log::info("Rozetka: synced {$synced} attributes for category {$rozetkaCategoryId} via items-create/attributes");

        return $synced;
    }

    /**
     * Fetch all attributes across all pages from /items-create/attributes.
     */
    protected function fetchAllAttributes(int $categoryId): array
    {
        $all = [];
        $page = 1;
        $maxPages = 50;

        do {
            try {
                $response = $this->client->get('/items-create/attributes', [
                    'category_id' => $categoryId,
                    'page' => $page,
                ]);
            } catch (\Exception $e) {
                Log::warning("Rozetka: items-create/attributes failed for category {$categoryId}: {$e->getMessage()}");

                return [];
            }

            $content = $response['content'] ?? [];
            $attrs = $content['attributes'] ?? [];
            $meta = $content['_meta'] ?? [];
            $totalPages = $meta['pageCount'] ?? 1;

            foreach ($attrs as $attr) {
                $all[] = $attr;
            }

            $page++;
        } while ($page <= min($totalPages, $maxPages));

        return $all;
    }

    /**
     * Fetch attribute values from /items-create/values (paginated).
     *
     * @return array<array{id: int, name: string}>|null
     */
    public function fetchAttributeValues(int $categoryId, int $attributeId): ?array
    {
        $all = [];
        $page = 1;

        do {
            try {
                $response = $this->client->get('/items-create/values', [
                    'category_id' => $categoryId,
                    'attribute_id' => $attributeId,
                    'page' => $page,
                ]);
            } catch (\Exception $e) {
                break;
            }

            $content = $response['content'] ?? [];
            $values = $content['attributeValues'] ?? [];
            $meta = $content['_meta'] ?? [];
            $totalPages = $meta['pageCount'] ?? 1;

            foreach ($values as $val) {
                $all[] = [
                    'id' => $val['id'],
                    'name' => $val['title_ua'] ?? $val['title'] ?? '',
                ];
            }

            $page++;
        } while ($page <= $totalPages);

        return ! empty($all) ? $all : null;
    }

    /**
     * Legacy sync via /v1/market-categories/category-options.
     */
    protected function syncCategoryAttributesLegacy(int $rozetkaCategoryId): int
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

        Log::info("Rozetka: synced {$synced} attributes for category {$rozetkaCategoryId} via legacy category-options");

        return $synced;
    }

    /**
     * Get cached attributes for a category, sync from API if empty.
     */
    public function getAttributesForCategory(int $rozetkaCategoryId)
    {
        $attrs = RozetkaCategoryAttribute::where('rozetka_category_id', $rozetkaCategoryId)->get();

        if ($attrs->isEmpty()) {
            $this->syncCategoryAttributes($rozetkaCategoryId);
            $attrs = RozetkaCategoryAttribute::where('rozetka_category_id', $rozetkaCategoryId)->get();
        }

        return $attrs;
    }
}
