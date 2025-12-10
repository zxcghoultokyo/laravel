<?php

namespace App\Services\Horoshop;

use App\Models\Product;
use App\Services\Ai\AiRouter;
use Illuminate\Support\Facades\Log;

class ProductService
{
    public function __construct(
        private HoroshopClient $client,
        private AiRouter $router,
    ) {}

    /**
     * Пошук товарів спочатку по локальній БД, а при необхідності — по Horoshop API.
     */
    public function searchByText(string $query, ?string $language = 'uk'): array
    {
        // 1. Нормалізуємо запит через AiRouter (прибираємо сміття, додаємо синоніми).
        $normalizedQuery = $this->router->normalizeSearchQuery($query, $language);

        $originalLower   = mb_strtolower($query);
        $normalizedLower = mb_strtolower($normalizedQuery);
        // ВАЖЛИВО: контекст = оригінал + нормалізований текст
        $context         = $originalLower . ' ' . $normalizedLower;

        Log::info('ProductService::searchByText', [
            'raw_query'        => $query,
            'normalized_query' => $normalizedQuery,
        ]);

        // 2. Базовий запит по БД.
        // Для простоти поки що шукаємо цілою фразою + "перевернутою" фразою.
        $like         = '%' . $normalizedQuery . '%';
        $words        = preg_split('/\s+/u', $normalizedQuery, -1, PREG_SPLIT_NO_EMPTY);
        $reversed     = implode(' ', array_reverse($words));
        $likeReversed = '%' . $reversed . '%';

        $baseQuery = Product::query()
            ->where(function ($q) use ($like, $likeReversed) {
                $q->where('search_index', 'LIKE', $like)
                  ->orWhere('search_index', 'LIKE', $likeReversed);
            });

        /**
         * 3. Категорійні хінти.
         *
         * Тепер дивимось і на оригінальний текст користувача, і на нормалізований текст
         * через змінну $context. Це дозволяє НЕ втратити ключові слова типу "турнікет",
         * навіть якщо LLM їх випадково викинув з normalizedQuery.
         */
        $categoryFilter = null;

        // Плитоноски
        if (
            str_contains($context, 'плитоноска') ||
            str_contains($context, 'plytonoska') ||
            str_contains($context, 'plate carrier') ||
            str_contains($context, 'platecarrier')
        ) {
            $categoryFilter = 'плитоноски';
        }
        // Турнікетні підсумки
        elseif (
            str_contains($context, 'турнікет') ||
            str_contains($context, 'turniket') ||
            str_contains($context, 'tq')
        ) {
            $categoryFilter = 'турнікет';
        }
        // Пістолетні підсумки / Glock
        elseif (
            str_contains($context, 'глок') ||
            str_contains($context, 'glock')
        ) {
            $categoryFilter = 'glock';
        }

        if ($categoryFilter) {
            $baseQuery->where(function ($q) use ($categoryFilter) {
                $q->where('search_index', 'LIKE', '%' . $categoryFilter . '%');
            });
        }

        // 4. Сортування: спочатку більш "правильні" категорії.
        $products = $baseQuery
            ->select('*')
            ->orderByRaw("
                CASE
                    WHEN category_path LIKE '%Тактичне спорядження/Плитоноски%' THEN 3
                    WHEN category_path LIKE '%Медичні підсумки/Турнікету%' THEN 2
                    WHEN category_path LIKE '%Тактичне спорядження/Підсумки/%' THEN 1
                    ELSE 0
                END DESC
            ")
            ->orderBy('price')
            ->limit(10)
            ->get();

        if ($products->isEmpty()) {
            Log::info('ProductService::searchByText no local results, fallback to Horoshop', [
                'query' => $query,
            ]);

            // 5. Fallback: Horoshop API (як було раніше).
            $remoteProducts = $this->client->get('catalog/search', [
                'q'        => $query,
                'language' => $language,
            ]);

            if (!is_array($remoteProducts) || empty($remoteProducts)) {
                return [];
            }

            return array_map(function ($row) {
                return [
                    'title'     => $row['title']['ua'] ?? $row['title']['ru'] ?? '',
                    'article'   => $row['article'] ?? null,
                    'price'     => $row['price'] ?? 0,
                    'price_old' => $row['price_old'] ?? 0,
                    'slug'      => $row['slug'] ?? null,
                    'link'      => $row['link'] ?? null,
                    'category'  => $row['parent']['value'] ?? null,
                    'images'    => $row['images'] ?? [],
                    '_raw'      => $row,
                ];
            }, $remoteProducts);
        }

        // 6. Мапимо локальні товари у формат для фронтенду.
        return $products->map(function (Product $product) {
            return [
                'id'                   => $product->id,
                'article'              => $product->article,
                'title'                => $product->title,
                'title_json'           => $product->title_json,
                'price'                => $product->price,
                'price_old'            => $product->price_old,
                'category_path'        => $product->category_path,
                'slug'                 => $product->slug,
                'link'                 => $product->link,
                'images'               => $product->images,
                'raw'                  => $product->raw,
                'search_index'         => $product->search_index,
                'orders_count'         => $product->orders_count,
                'views_count'          => $product->views_count,
                'added_to_cart_count'  => $product->added_to_cart_count,
                'created_at'           => $product->created_at,
                'updated_at'           => $product->updated_at,
            ];
        })->all();
    }

    /**
     * Допоміжний метод для отримання товарів за артикулом (для AI-рекомендацій, тощо).
     */
    public function getByArticles(array $articles): array
    {
        if (empty($articles)) {
            return [];
        }

        return Product::query()
            ->whereIn('article', $articles)
            ->get()
            ->map(function (Product $product) {
                return [
                    'id'                   => $product->id,
                    'article'              => $product->article,
                    'title'                => $product->title,
                    'title_json'           => $product->title_json,
                    'price'                => $product->price,
                'price_old'            => $product->price_old,
                    'category_path'        => $product->category_path,
                    'slug'                 => $product->slug,
                    'link'                 => $product->link,
                    'images'               => $product->images,
                    'raw'                  => $product->raw,
                    'search_index'         => $product->search_index,
                    'orders_count'         => $product->orders_count,
                    'views_count'          => $product->views_count,
                    'added_to_cart_count'  => $product->added_to_cart_count,
                    'created_at'           => $product->created_at,
                    'updated_at'           => $product->updated_at,
                ];
            })
            ->all();
    }
}
