<?php

namespace App\Services\Horoshop;

/**
 * Сервіс для роботи з товарами Хорошопа через catalog/export.
 */
class ProductService
{
    public function __construct(
        protected HoroshopClient $client
    ) {}

    /**
     * Пошук товарів через catalog/export з простим фільтром по тексту.
     */
    public function search(?string $query = null, array $filters = []): array
    {
        $expr = [];

        // фільтр по категорії, якщо передали
        if (!empty($filters['category_id'])) {
            $expr['parent.id'] = (int) $filters['category_id'];
        }

        $payload = [
            'expr'           => $expr ?: new \stdClass(), // порожній об'єкт, якщо немає умов
            'limit'          => $filters['limit'] ?? 50,
            'offset'         => $filters['offset'] ?? 0,
            'includedParams' => [
                'price',
                'price_old',
                'title',
                'short_description',
                'description',
                'article',
                'parent_article',
                'images',
                'link',
            ],
        ];

        $response = $this->client->call('catalog/export', $payload);

        $products = $response['products'] ?? [];

        if ($query === null || trim($query) === '') {
            return $products;
        }

        $q = mb_strtolower(trim($query));

        $filtered = array_filter($products, function (array $product) use ($q) {
            $titleUa = $product['title']['ua'] ?? ($product['title']['ru'] ?? '');
            $titleRu = $product['title']['ru'] ?? '';
            $article = $product['article'] ?? '';

            $haystack = mb_strtolower($titleUa.' '.$titleRu.' '.$article);

            return str_contains($haystack, $q);
        });

        return array_values($filtered);
    }

    /**
     * Отримати конкретний товар по артикулу.
     */
    public function getByArticle(string $article): ?array
    {
        $payload = [
            'expr' => [
                'article' => [$article],
            ],
            'limit'          => 10,
            'includedParams' => [
                'price',
                'price_old',
                'title',
                'short_description',
                'description',
                'article',
                'parent_article',
                'images',
                'link',
            ],
        ];

        $response = $this->client->call('catalog/export', $payload);

        $products = $response['products'] ?? [];

        return $products[0] ?? null;
    }

    /**
     * На ма*
