<?php

namespace App\Services\Horoshop;

use App\Models\HoroshopProduct;
use App\Models\RozetkaProduct;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class HoroshopCatalogService
{
    /**
     * Sync ALL Horoshop products into horoshop_products table.
     * This is separate from the chat-bot products table.
     *
     * @return array{total: int, created: int, updated: int, matched: int}
     */
    public function syncFullCatalog(Tenant $tenant, int $limit = 500): array
    {
        $client = $this->makeClient($tenant);

        $offset = 0;
        $created = 0;
        $updated = 0;
        $total = 0;

        $cacheKey = "horoshop_catalog_sync_running_{$tenant->id}";
        Cache::put($cacheKey, true, now()->addHours(2));

        do {
            if (Cache::get($cacheKey) === false) {
                Log::info('Horoshop catalog sync cancelled', ['tenant_id' => $tenant->id]);
                break;
            }

            $payload = [
                'limit' => $limit,
                'offset' => $offset,
                'includedParams' => [
                    'title', 'article', 'parent_article', 'price', 'price_old',
                    'parent', 'images', 'slug', 'link', 'presence', 'quantity',
                    'display_in_showcase', 'popularity', 'color', 'brand',
                    'description', 'characteristics', 'short_description',
                    'select', 'params', 'mod_title',
                    'gallery_common', 'seo_title', 'seo_keywords', 'seo_description',
                    'we_recommended', 'icons',
                    'Rozmir', 'Kolir', 'Dovzhina',
                    'rozmir', 'kolir', 'dovzhina',
                ],
            ];

            Log::info('Horoshop catalog sync request', [
                'tenant_id' => $tenant->id,
                'offset' => $offset,
                'limit' => $limit,
            ]);

            try {
                $response = $client->request('catalog/export', $payload);
            } catch (\Exception $e) {
                Log::error('Horoshop catalog sync error', [
                    'tenant_id' => $tenant->id,
                    'offset' => $offset,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }

            $products = Arr::get($response, 'products', []);

            if (empty($products)) {
                break;
            }

            foreach ($products as $item) {
                $result = $this->upsertHoroshopProduct($tenant, $item);
                if ($result === 'created') {
                    $created++;
                } elseif ($result === 'updated') {
                    $updated++;
                }
                $total++;
            }

            // Update progress in cache
            Cache::put("horoshop_catalog_sync_progress_{$tenant->id}", [
                'total' => $total,
                'created' => $created,
                'updated' => $updated,
                'offset' => $offset,
            ], now()->addHours(2));

            $offset += $limit;
        } while (true);

        Cache::forget($cacheKey);

        // After sync, match with Rozetka products by article
        $matched = $this->matchWithRozetka($tenant);

        Log::info('Horoshop catalog sync complete', [
            'tenant_id' => $tenant->id,
            'total' => $total,
            'created' => $created,
            'updated' => $updated,
            'matched' => $matched,
        ]);

        return compact('total', 'created', 'updated', 'matched');
    }

    /**
     * Upsert a single product into horoshop_products.
     */
    protected function upsertHoroshopProduct(Tenant $tenant, array $item): string
    {
        $article = $item['article'] ?? null;

        if (! $article) {
            return 'skipped';
        }

        $product = HoroshopProduct::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->where('article', $article)
            ->first();

        $isNew = ! $product;

        if (! $product) {
            $product = new HoroshopProduct;
            $product->tenant_id = $tenant->id;
        }

        $title = $item['title']['ua'] ?? $item['title']['ru'] ?? ($item['title'] ?? null);
        if (is_array($title)) {
            $title = $title['ua'] ?? $title['ru'] ?? null;
        }

        $brand = Arr::get($item, 'brand.value.ua')
            ?? Arr::get($item, 'brand.value.ru')
            ?? Arr::get($item, 'brand')
            ?? null;
        if (is_array($brand)) {
            $brand = $brand['value']['ua'] ?? $brand['value']['ru'] ?? null;
        }

        $presence = Arr::get($item, 'presence.value.ua')
            ?? Arr::get($item, 'presence.value.ru')
            ?? null;
        if (is_array($presence)) {
            $presence = null;
        }

        $descriptionUa = Arr::get($item, 'description.value.ua')
            ?? Arr::get($item, 'description.ua')
            ?? null;
        $descriptionRu = Arr::get($item, 'description.value.ru')
            ?? Arr::get($item, 'description.ru')
            ?? null;

        $shortDescUa = Arr::get($item, 'short_description.value.ua')
            ?? Arr::get($item, 'short_description.ua')
            ?? null;
        $shortDescRu = Arr::get($item, 'short_description.value.ru')
            ?? Arr::get($item, 'short_description.ru')
            ?? null;

        $color = $this->extractColor($item);
        $size = $this->extractSize($item, $title);

        $product->fill([
            'article' => $this->truncate($article, 500),
            'parent_article' => $this->truncate($item['parent_article'] ?? null, 500),
            'title' => $this->truncate(is_string($title) ? $title : null, 500),
            'title_json' => $item['title'] ?? null,
            'price' => $item['price'] ?? 0,
            'price_old' => $item['price_old'] ?? null,
            'brand' => $this->truncate(is_string($brand) ? $brand : null, 255),
            'color' => $this->truncate($color, 255),
            'size' => $this->truncate($size, 255),
            'category_path' => $this->truncate($item['parent']['value'] ?? null, 500),
            'in_stock' => $this->isInStock($item),
            'quantity' => $item['quantity'] ?? 0,
            'presence' => $this->truncate(is_string($presence) ? $presence : null, 255),
            'display_in_showcase' => (bool) ($item['display_in_showcase'] ?? false),
            'description_ua' => is_string($descriptionUa) ? $descriptionUa : null,
            'description_ru' => is_string($descriptionRu) ? $descriptionRu : null,
            'short_description_ua' => is_string($shortDescUa) ? $shortDescUa : null,
            'short_description_ru' => is_string($shortDescRu) ? $shortDescRu : null,
            'images' => $item['images'] ?? [],
            'gallery_common' => $item['gallery_common'] ?? null,
            'characteristics' => $item['characteristics'] ?? null,
            'seo_title' => $item['seo_title'] ?? null,
            'seo_keywords' => $item['seo_keywords'] ?? null,
            'seo_description' => $item['seo_description'] ?? null,
            'slug' => $this->truncate($item['slug'] ?? null, 500),
            'link' => $this->truncate($item['link'] ?? null, 1000),
            'popularity' => $item['popularity'] ?? 0,
            'we_recommended' => (bool) ($item['we_recommended'] ?? false),
            'icons' => $item['icons'] ?? null,
            'mod_title' => $this->truncate($item['mod_title'] ?? null, 500),
            'raw' => $item,
            'synced_at' => now(),
        ]);

        $product->save();

        return $isNew ? 'created' : 'updated';
    }

    /**
     * Match horoshop_products ↔ rozetka_products by article.
     */
    public function matchWithRozetka(Tenant $tenant): int
    {
        $matched = 0;

        // Get all Rozetka products for this tenant (article → id)
        $rozetkaMap = RozetkaProduct::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('article')
            ->where('article', '!=', '')
            ->pluck('id', 'article')
            ->toArray();

        if (empty($rozetkaMap)) {
            return 0;
        }

        // Chunk through all Horoshop products and match
        HoroshopProduct::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->chunkById(500, function ($products) use ($rozetkaMap, &$matched) {
                foreach ($products as $product) {
                    $rozetkaId = $rozetkaMap[$product->article] ?? null;

                    if ($rozetkaId && $product->rozetka_product_id !== $rozetkaId) {
                        $product->rozetka_product_id = $rozetkaId;
                        $product->save();
                        $matched++;
                    }
                }
            });

        Log::info('Horoshop ↔ Rozetka matching complete', [
            'tenant_id' => $tenant->id,
            'matched' => $matched,
            'rozetka_count' => count($rozetkaMap),
        ]);

        return $matched;
    }

    protected function makeClient(Tenant $tenant): HoroshopClient
    {
        $credentials = $tenant->platform_credentials;

        if (empty($credentials) || empty($credentials['domain'])) {
            throw new \RuntimeException('Tenant has no Horoshop credentials');
        }

        $domain = is_array($credentials['domain'])
            ? ($credentials['domain']['value'] ?? '')
            : (string) $credentials['domain'];
        $login = is_array($credentials['login'])
            ? ($credentials['login']['value'] ?? '')
            : (string) $credentials['login'];
        $password = is_array($credentials['password'])
            ? ($credentials['password']['value'] ?? '')
            : (string) $credentials['password'];

        return new HoroshopClient($domain, $login, $password);
    }

    protected function extractColor(array $item): ?string
    {
        // Try various color field names
        foreach (['color', 'Kolir', 'kolir'] as $key) {
            $val = Arr::get($item, "{$key}.value.ua")
                ?? Arr::get($item, "{$key}.value.ru")
                ?? Arr::get($item, "{$key}.value")
                ?? Arr::get($item, $key);

            if (is_string($val) && $val !== '') {
                return $val;
            }
        }

        return null;
    }

    protected function extractSize(array $item, ?string $title): ?string
    {
        foreach (['Rozmir', 'rozmir'] as $key) {
            $val = Arr::get($item, "{$key}.value.ua")
                ?? Arr::get($item, "{$key}.value.ru")
                ?? Arr::get($item, "{$key}.value")
                ?? Arr::get($item, $key);

            if (is_string($val) && $val !== '') {
                return $val;
            }
        }

        // Fallback: extract from characteristics
        $chars = $item['characteristics'] ?? [];
        if (is_array($chars)) {
            foreach ($chars as $char) {
                $name = $char['name'] ?? ($char['title'] ?? '');
                if (is_string($name) && preg_match('/розмір|размер|size/iu', $name)) {
                    return $char['value'] ?? null;
                }
            }
        }

        return null;
    }

    protected function isInStock(array $item): bool
    {
        if (($item['quantity'] ?? 0) > 0) {
            return true;
        }

        $presence = Arr::get($item, 'presence.value.ua')
            ?? Arr::get($item, 'presence.value.ru')
            ?? '';

        return is_string($presence) && mb_stripos($presence, 'в наявності') !== false;
    }

    protected function truncate(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_substr($value, 0, $max);
    }
}
