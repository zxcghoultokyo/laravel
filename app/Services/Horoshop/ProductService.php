<?php

namespace App\Services\Horoshop;

class ProductService
{
    public function __construct(
        protected HoroshopClient $client
    ) {}

    // Пошук товарів через catalog/export з простим фільтром по тексту
    public function search(?string $query = null, array $filters = []): array
    {
        $expr = [];

        // Якщо передали category_id — фільтруємо по parent.id
        if (! empty($filters['category_id'])) {
            $expr['parent.id'] = (int) $filters['category_id'];
        }

        $payload = [
            // Якщо немає умов — передаємо порожній об'єкт {}
            'expr'           => $expr ?: new \stdClass(),
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

        // Якщо немає текстового запиту — повертаємо все як є
        if ($query === null || trim($query) === '') {
            return $products;
        }

        $q = mb_strtolower(trim($query));

        // Локальний фільтр по назві та артикулу
        $filtered = array_filter($products, function (array $product) use ($q) {
            $titleUa = $product['title']['ua'] ?? ($product['title']['ru'] ?? '');
            $titleRu = $product['title']['ru'] ?? '';
            $article = $product['article'] ?? '';

            $haystack = mb_strtolower($titleUa . ' ' . $titleRu . ' ' . $article);

            return str_contains($haystack, $q);
        });

        return array_values($filtered);
    }

    // Отримати один товар по артикулу
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

    // Батчевий експорт (щоб потім синкати в свою БД)
    public function exportBatch(int $offset = 0, int $limit = 500): array
    {
        $payload = [
            'expr'           => new \stdClass(),
            'limit'          => $limit,
            'offset'         => $offset,
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
                'parent',
                'brand',
            ],
        ];

        $response = $this->client->call('catalog/export', $payload);

        return $response['products'] ?? [];
    }
}
