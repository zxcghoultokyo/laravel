<?php

namespace App\Services\Horoshop;

use App\Models\Product;
use Illuminate\Support\Arr;
use Throwable;

class ProductService
{
    public function __construct(
        protected HoroshopClient $client,
    ) {}

    /**
     * Пошук товарів: спочатку в локальній БД, потім (fallback) напряму в Horoshop.
     */
    public function searchByText(string $query, ?int $limit = 10): array
    {
        $query = trim($query);

        if ($query === '') {
            return [];
        }

        // 1) Пошук у локальній БД
        $local = $this->searchLocal($query, $limit);

        if (!empty($local)) {
            return $local;
        }

        // 2) Якщо в БД нічого не знайшли – fallback до прямого Horoshop API
        $remote = $this->searchRemoteAndOptionallySync($query, $limit);

        return $remote;
    }

    /**
     * Примітивний LIKE-пошук по нашій таблиці products.
     */
    protected function searchLocal(string $query, ?int $limit = 10): array
    {
        $needle = mb_strtolower($query, 'UTF-8');

        $builder = Product::query()
            ->where('search_index', 'LIKE', '%' . $needle . '%')
            ->orderByDesc('orders_count')
            ->orderByDesc('views_count');

        if ($limit !== null) {
            $builder->limit($limit);
        }

        $items = $builder->get();

        if ($items->isEmpty()) {
            return [];
        }

        return $items->map(function (Product $p) {
            return [
                'title'     => $p->title,
                'article'   => $p->article,
                'price'     => $p->price,
                'price_old' => $p->price_old,
                'slug'      => $p->slug,
                'link'      => $p->link,
                'category'  => $p->category_path,
                'images'    => $p->images ?? [],
                '_raw'      => $p->raw ?? [],
            ];
        })->all();
    }

    /**
     * Старий спосіб: тягнемо catalog/export напряму, фільтруємо по назві,
     * і по дорозі оновлюємо/створюємо записи в локальній БД.
     */
    protected function searchRemoteAndOptionallySync(string $query, ?int $limit = 10): array
    {
        try {
            $response = $this->client->request('catalog/export', [
                'limit'          => 500,
                'includedParams' => [
                    'title',
                    'price',
                    'price_old',
                    'article',
                    'slug',
                    'link',
                    'images',
                    'gallery_common',
                    'gallery_360',
                    'parent',
                ],
            ]);

            $products = $response['products'] ?? [];

            if (empty($products)) {
                return [];
            }

            $normalized = [];
            $needle     = mb_strtolower($query, 'UTF-8');

            foreach ($products as $raw) {
                $article = (string) ($raw['article'] ?? '');

                if ($article === '') {
                    continue;
                }

                $titleRaw = $raw['title'] ?? '';
                $titleUa  = '';
                $titleRu  = '';

                if (is_array($titleRaw)) {
                    $titleUa = (string) Arr::get($titleRaw, 'ua', '');
                    $titleRu = (string) Arr::get($titleRaw, 'ru', '');
                } else {
                    $titleUa = (string) $titleRaw;
                }

                $titleUaLower = mb_strtolower($titleUa, 'UTF-8');
                $titleRuLower = mb_strtolower($titleRu, 'UTF-8');

                if (
                    ! str_contains($titleUaLower, $needle)
                    && ! str_contains($titleRuLower, $needle)
                ) {
                    // Не співпало по тексту – пропускаємо
                    continue;
                }

                $categoryPath = (string) Arr::get($raw, 'parent.value', '');

                $images = $raw['images']
                    ?? $raw['gallery_common']
                    ?? $raw['gallery_360']
                    ?? [];

                $searchIndex = mb_strtolower(
                    trim($titleUa . ' ' . $titleRu . ' ' . $categoryPath),
                    'UTF-8'
                );

                // Оновлюємо/створюємо запис в нашій БД
                Product::updateOrCreate(
                    ['article' => $article],
                    [
                        'title'         => $titleUa !== '' ? $titleUa : ($titleRu ?: $titleRaw),
                        'title_json'    => is_array($titleRaw) ? $titleRaw : null,
                        'price'         => $raw['price'] ?? null,
                        'price_old'     => $raw['price_old'] ?? null,
                        'category_path' => $categoryPath,
                        'slug'          => $raw['slug'] ?? null,
                        'link'          => $raw['link'] ?? null,
                        'images'        => $images,
                        'raw'           => $raw,
                        'search_index'  => $searchIndex,
                    ]
                );

                $normalized[] = [
                    'title'      => $titleUa !== '' ? $titleUa : ($titleRu ?: $titleRaw),
                    'article'    => $article,
                    'price'      => $raw['price'] ?? null,
                    'price_old'  => $raw['price_old'] ?? null,
                    'slug'       => $raw['slug'] ?? null,
                    'link'       => $raw['link'] ?? null,
                    'category'   => $categoryPath,
                    'images'     => $images,
                    '_raw'       => $raw,
                ];

                if ($limit !== null && count($normalized) >= $limit) {
                    break;
                }
            }

            return $normalized;
        } catch (Throwable $e) {
            report($e);
            return [];
        }
    }
}
