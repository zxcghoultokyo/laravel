<?php

namespace App\Services\Rozetka;

use App\Models\Product;
use App\Models\RozetkaProduct;
use Illuminate\Support\Facades\Log;

class RozetkaProductService
{
    public function __construct(protected RozetkaClient $client) {}

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

        Log::info("Rozetka: synced {$synced} products for tenant {$tenantId}");

        return $synced;
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
                'group_id' => $item['rz_group_id'] ?? $item['group_id'] ?? null,
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
                return $reason['reason'] ?? $reason['text'] ?? $reason['message'] ?? json_encode($reason);
            }

            return null;
        }, $reasons));
    }
}
