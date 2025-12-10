<?php

namespace App\Services\Horoshop;

use Illuminate\Support\Arr;
use Throwable;

/**
 * Сервіс для роботи з товарами Хорошопа.
 *
 * ЗАРАЗ:
 *  - робить виклик catalog/export
 *  - фільтрує товари по назві (простий contains по ua/ru)
 *
 * У МАЙБУТНЬОМУ:
 *  - замінимо на роботу з локальною БД (sync + індекс)
 */
class ProductService
{
    public function __construct(
        protected HoroshopClient $client,
    ) {}

    /**
     * Пошук товарів за текстом (наївний варіант).
     *
     * @param  string      $query  Текст від юзера (наприклад: "потрібна плитоноска")
     * @param  int|null    $limit  Скільки товарів повернути
     * @return array                Масив нормалізованих товарів
     */
    public function searchByText(string $query, ?int $limit = 10): array
    {
        $query = trim($query);

        if ($query === '') {
            return [];
        }

        try {
            // 1) Тягнемо пачку товарів з Хорошопа
            //    (поки без expr, просто перші N, а потім фільтруємо по назві)
            $response = $this->client->request('catalog/export', [
                'limit'          => 500, // максимум, що дозволяє Хорошоп
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

            foreach ($products as $product) {
                // Дістаємо тайтли
                $titleRaw = $product['title'] ?? '';
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

                // Простий фулл-текст: чи містить назва наш запит
                if (
                    ! str_contains($titleUaLower, $needle)
                    && ! str_contains($titleRuLower, $needle)
                ) {
                    continue;
                }

                $normalized[] = [
                    'title'      => $titleUa !== '' ? $titleUa : ($titleRu ?: $titleRaw),
                    'article'    => $product['article'] ?? null,
                    'price'      => $product['price'] ?? null,
                    'price_old'  => $product['price_old'] ?? null,
                    'slug'       => $product['slug'] ?? null,
                    'link'       => $product['link'] ?? null,
                    'category'   => Arr::get($product, 'parent.value'),
                    'images'     => $product['images']
                        ?? $product['gallery_common']
                        ?? $product['gallery_360']
                        ?? [],
                    '_raw'       => $product, // про запас, якщо щось ще треба
                ];

                if ($limit !== null && count($normalized) >= $limit) {
                    break;
                }
            }

            return $normalized;
        } catch (Throwable $e) {
            // Щоб чат не падав 500-ми – просто лог і пустий результат
            report($e);
            return [];
        }
    }
}
