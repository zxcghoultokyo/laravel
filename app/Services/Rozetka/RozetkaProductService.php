<?php

namespace App\Services\Rozetka;

use App\Models\RozetkaProduct;
use Illuminate\Support\Facades\Log;

class RozetkaProductService
{
    public function __construct(protected RozetkaClient $client) {}

    /**
     * Sync all products from Rozetka Seller API for given tenant.
     */
    public function syncProducts(int $tenantId): int
    {
        $page = 1;
        $perPage = 20; // Rozetka max
        $synced = 0;

        do {
            $response = $this->client->get('/items/search', [
                'page' => $page,
                'per_page' => $perPage,
            ]);

            $items = $response['content']['items'] ?? $response['content'] ?? [];

            if (empty($items)) {
                break;
            }

            foreach ($items as $item) {
                $this->upsertProduct($tenantId, $item);
                $synced++;
            }

            $totalPages = $response['content']['total_pages']
                ?? (int) ceil(($response['content']['total_count'] ?? 0) / $perPage);

            $page++;
        } while ($page <= $totalPages);

        Log::info("Rozetka: synced {$synced} products for tenant {$tenantId}");

        return $synced;
    }

    protected function upsertProduct(int $tenantId, array $item): RozetkaProduct
    {
        $photos = $this->extractPhotos($item);

        return RozetkaProduct::withoutGlobalScopes()->updateOrCreate(
            ['rozetka_item_id' => $item['id']],
            [
                'tenant_id' => $tenantId,
                'article' => $item['article'] ?? '',
                'parent_article' => $item['parent_article'] ?? null,
                'title' => $item['title'] ?? $item['name'] ?? '',
                'price' => $item['price'] ?? 0,
                'price_old' => $item['old_price'] ?? $item['price_old'] ?? null,
                'rozetka_category_id' => $item['category_id'] ?? null,
                'rozetka_category_name' => $item['category_name'] ?? null,
                'in_stock' => ($item['sell_status'] ?? '') === 'available',
                'quantity' => $item['quantity'] ?? $item['quantity_in_stock'] ?? 0,
                'moderation_status' => $item['moderation_status'] ?? 0,
                'status' => $item['status'] ?? 'active',
                'group_id' => $item['group_id'] ?? 0,
                'photos' => $photos,
                'raw' => $item,
                'synced_at' => now(),
            ]
        );
    }

    protected function extractPhotos(array $item): array
    {
        $photos = [];

        if (! empty($item['photo'])) {
            $photos[] = ['url' => $item['photo']];
        }

        if (! empty($item['photos'])) {
            foreach ($item['photos'] as $p) {
                if (is_string($p)) {
                    $photos[] = ['url' => $p];
                } elseif (is_array($p) && isset($p['url'])) {
                    $photos[] = $p;
                }
            }
        }

        return $photos;
    }
}
