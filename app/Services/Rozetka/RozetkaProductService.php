<?php

namespace App\Services\Rozetka;

use App\Models\Product;
use App\Models\RozetkaProduct;
use Illuminate\Support\Facades\Log;

class RozetkaProductService
{
    public function __construct(protected RozetkaClient $client) {}

    /**
     * Push product data to Rozetka via mass-update-basic-data endpoint.
     *
     * @return array{success: bool, message: string, items_updated?: int}
     */
    public function pushToRozetka(RozetkaProduct $product, bool $autoApprove = false): array
    {
        $itemId = $product->rozetka_item_id ?? null;
        $raw = $product->raw ?? [];
        $internalItemId = $raw['item_id'] ?? null;

        if (! $itemId && ! $internalItemId) {
            return ['success' => false, 'message' => 'Товар не має ID в системі Розетки'];
        }

        $itemData = $this->buildItemPayload($product);

        if ($itemId) {
            $itemData['rz_item_id'] = (int) $itemId;
        } else {
            $itemData['item_id'] = (int) $internalItemId;
        }

        $payload = ['items' => [$itemData]];
        if ($autoApprove) {
            $payload['is_approve'] = true;
        }

        try {
            $response = $this->client->put('/items-create/mass-update-basic-data', $payload, [
                'Content-Language' => 'uk',
            ]);

            $success = $response['success'] ?? false;

            if ($success) {
                $updated = $response['content']['items_updated'] ?? 0;

                $product->update([
                    'has_local_changes' => false,
                    'edited_fields' => null,
                    'change_status' => 1,
                ]);

                Log::info("Rozetka: pushed product {$product->article} to Rozetka, items_updated={$updated}");

                return ['success' => true, 'message' => 'Товар оновлено на Розетці', 'items_updated' => $updated];
            }

            $errorMsg = $response['errors']['message'] ?? $response['errors']['description'] ?? 'Невідома помилка';
            Log::warning("Rozetka: push failed for {$product->article}: {$errorMsg}", $response);

            return ['success' => false, 'message' => "Помилка: {$errorMsg}"];
        } catch (\Exception $e) {
            Log::error("Rozetka: push exception for {$product->article}: {$e->getMessage()}");

            return ['success' => false, 'message' => "Помилка з'єднання: {$e->getMessage()}"];
        }
    }

    /**
     * Build the item payload for mass-update-basic-data.
     */
    protected function buildItemPayload(RozetkaProduct $product): array
    {
        $raw = $product->raw ?? [];
        $item = [];

        $item['name'] = $product->title;
        $item['name_ua'] = $product->title;

        if ($product->description) {
            $item['description'] = $product->description;
        }
        if ($product->description_ua) {
            $item['description_ua'] = $product->description_ua;
        }

        if ($product->article) {
            $item['article'] = $product->article;
        }

        // Producer
        if ($product->producer_name) {
            $producerId = $raw['rz_producer']['id'] ?? 0;
            $item['producer'] = [
                'id' => $producerId,
                'title' => $product->producer_name,
            ];
        }

        // Pictures from clean URLs
        $photos = $product->clean_photo_urls;
        if (! empty($photos)) {
            $item['pictures'] = array_map(fn (string $url) => ['link' => $url], $photos);
        }

        // Params (characteristics) from saved attribute values
        $params = $this->buildParams($product);
        if (! empty($params)) {
            $item['params'] = $params;
        }

        return $item;
    }

    /**
     * Build params array from saved attribute values.
     *
     * @return array<array{id: int, title: string, type: string, value: mixed}>
     */
    protected function buildParams(RozetkaProduct $product): array
    {
        $savedValues = $product->attributeValues()->with('categoryAttribute')->get();

        if ($savedValues->isEmpty()) {
            return [];
        }

        $params = [];
        foreach ($savedValues as $val) {
            $attr = $val->categoryAttribute;
            if (! $attr) {
                continue;
            }

            $type = $attr->attr_type ?? 'TextInput';
            $paramValue = $this->formatParamValue($type, $val->value_id, $val->value_text);

            if ($paramValue === null) {
                continue;
            }

            $params[] = [
                'id' => $attr->attribute_id,
                'title' => $attr->name,
                'type' => $type,
                'value' => $paramValue,
            ];
        }

        return $params;
    }

    /**
     * Format param value based on attribute type for the Rozetka API.
     */
    protected function formatParamValue(string $type, ?string $valueId, ?string $valueText): mixed
    {
        return match ($type) {
            'ComboBox', 'List', 'ListValues', 'CheckBoxGroup', 'CheckBoxGroupValues' => $valueId
                ? [['id' => (int) $valueId, 'title' => $valueText ?? '']]
                : null,
            'Integer' => $valueText !== null ? (int) $valueText : null,
            'Decimal' => $valueText !== null ? (float) $valueText : null,
            'CheckBox' => $valueText !== null ? (bool) $valueText : null,
            default => $valueText,
        };
    }

    /**
     * Sync all products from Rozetka Seller API using /goods/all endpoint.
     *
     * @param  callable|null  $onProgress  fn(int $synced, int $page, int $totalPages)
     */
    public function syncProducts(int $tenantId, ?callable $onProgress = null): int
    {
        $page = 1;
        $synced = 0;

        // Pre-load local article→id map for matching
        $localArticleMap = Product::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->pluck('id', 'article')
            ->toArray();

        do {
            $response = $this->client->get('/goods/all', [
                'page' => $page,
                'pageSize' => 100,
            ], [
                'Content-Language' => 'uk',
            ]);

            $content = $response['content'] ?? [];
            $items = $content['items'] ?? [];
            $meta = $content['_meta'] ?? [];
            $totalPages = $meta['pageCount'] ?? 1;

            if (empty($items)) {
                break;
            }

            foreach ($items as $item) {
                $this->upsertProduct($tenantId, $item, $localArticleMap);
                $synced++;
            }

            if ($onProgress) {
                $onProgress($synced, $page, $totalPages);
            }

            $page++;
        } while ($page <= $totalPages);

        $uniqueCount = RozetkaProduct::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where(function ($q) {
                $q->whereNotNull('rozetka_item_id')->orWhereNotNull('raw');
            })
            ->count();

        Log::info("Rozetka: synced {$synced} API items → {$uniqueCount} unique products for tenant {$tenantId}");

        return $uniqueCount;
    }

    /**
     * @param  array<string, int>  $localArticleMap  article → local product id
     */
    protected function upsertProduct(int $tenantId, array $item, array $localArticleMap): RozetkaProduct
    {
        $photos = $this->extractPhotos($item);
        $category = $item['rz_category'] ?? [];
        $producer = $item['rz_producer'] ?? [];
        $blockedReasons = $this->extractBlockedReasons($item);
        $article = $item['article'] ?? '';

        // Auto-match with local product by article
        $localProductId = $localArticleMap[$article] ?? null;

        return RozetkaProduct::withoutGlobalScopes()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'article' => $article,
            ],
            [
                'rozetka_item_id' => $item['rz_item_id'] ?? $item['item_id'] ?? null,
                'parent_article' => $item['parent_article'] ?? null,
                'title' => $item['name_ua'] ?? $item['name'] ?? '',
                'description' => $item['description_ua'] ?? $item['description'] ?? null,
                'description_ua' => $item['description_ua'] ?? null,
                'price' => $item['price'] ?? 0,
                'price_old' => ($item['price_old'] ?? '0.00') !== '0.00' ? $item['price_old'] : null,
                'rozetka_category_id' => $category['id'] ?? null,
                'rozetka_category_name' => $category['title_ua'] ?? $category['title'] ?? null,
                'in_stock' => ($item['available'] ?? 0) == 1,
                'quantity' => $item['stock_quantity'] ?? 0,
                'upload_status' => $item['upload_status'] ?? null,
                'upload_status_title' => $item['upload_status_title'] ?? null,
                'rz_status' => $item['rz_status'] ?? null,
                'rz_sell_status' => $item['rz_sell_status'] ?? null,
                'available' => $item['available'] ?? null,
                'available_title' => $item['available_title'] ?? null,
                'blocked_reasons' => $blockedReasons,
                'change_status' => $item['change_status'] ?? null,
                'producer_name' => $producer['title'] ?? null,
                'url' => $item['url'] ?? null,
                'status' => $item['upload_status'] ?? null,
                'group_id' => $item['rz_group_id'] ?? $item['group_id'] ?? 0,
                'local_product_id' => $localProductId,
                'photos' => $photos,
                'raw' => $item,
                'synced_at' => now(),
            ]
        );
    }

    protected function extractPhotos(array $item): array
    {
        $photos = [];

        if (! empty($item['photo']) && is_array($item['photo'])) {
            foreach ($item['photo'] as $url) {
                if (is_string($url)) {
                    $photos[] = $url;
                }
            }
        }

        if (empty($photos) && ! empty($item['photo_preview']) && is_array($item['photo_preview'])) {
            foreach ($item['photo_preview'] as $url) {
                if (is_string($url)) {
                    $photos[] = $url;
                }
            }
        }

        return $photos;
    }

    protected function extractBlockedReasons(array $item): array
    {
        $reasons = $item['blocked_reason'] ?? [];

        if (! is_array($reasons)) {
            return [];
        }

        return array_filter(array_map(function ($reason) {
            if (is_string($reason)) {
                return $reason;
            }
            if (is_array($reason)) {
                return $reason['title'] ?? $reason['reason'] ?? $reason['text'] ?? $reason['message'] ?? json_encode($reason, JSON_UNESCAPED_UNICODE);
            }

            return null;
        }, $reasons));
    }
}
