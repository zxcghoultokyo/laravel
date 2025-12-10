<?php

namespace App\Services\Horoshop;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Builder;

class ProductService
{
    protected HoroshopClient $client;

    public function __construct(HoroshopClient $client)
    {
        $this->client = $client;
    }

    /**
     * Головний метод пошуку товарів, який використовує ChatController.
     */
    public function searchByText(string $rawQuery, ?int $categoryId = null, string $language = 'uk'): array
    {
        Log::info('ProductService::searchByText', [
            'raw_query'   => $rawQuery,
            'category_id' => $categoryId,
            'language'    => $language,
        ]);

        $normalized = mb_strtolower(trim($rawQuery));
        if ($normalized === '') {
            return [];
        }

        // 1) додаємо доменні синоніми, щоб ловити "бронік", "турнікет", "глок" і т.д.
        $expandedQuery = $this->expandQueryWithDomainSynonyms($normalized, $language);

        // 2) шукаємо кандидатів у локальній БД
        $candidates = $this->findCandidates($expandedQuery, $categoryId);

        if ($candidates->isEmpty()) {
            Log::info('ProductService::searchByText no local results');
            return [];
        }

        // 3) рахуємо скоринг для кожного товару
        $scored = $this->scoreProducts($candidates, $expandedQuery);

        if ($scored->isEmpty()) {
            Log::info('ProductService::searchByText all candidates filtered out by score');
            return [];
        }

        // 4) фільтруємо за відносним порогом релевантності
        $maxScore = $scored->max('score') ?? 0.0;

        // якщо навіть найкращий товар має дуже малий скор – краще сказати, що немає результатів
        if ($maxScore < 1.0) {
            Log::info('ProductService::searchByText max score too low', ['max_score' => $maxScore]);
            return [];
        }

        // залишаємо товари, які набрали >= 50% від максимального скору
        $filtered = $scored->filter(function (array $row) use ($maxScore) {
            return $row['score'] >= $maxScore * 0.5;
        });

        if ($filtered->isEmpty()) {
            Log::info('ProductService::searchByText filtered collection empty after threshold', [
                'max_score' => $maxScore,
            ]);
            return [];
        }

        // 5) сортуємо та обрізаємо до топ-N
        $top = $filtered
            ->sortByDesc('score')
            ->values()
            ->take(20);

        // 6) нормалізуємо під API відповіді
        return $top
            ->map(function (array $row) {
                /** @var Product $product */
                $product = $row['product'];
                return $this->normalizeProductForApi($product);
            })
            ->all();
    }

    /**
     * Пошук кандидатів у таблиці products по search_index/title/category_path.
     */
    protected function findCandidates(string $expandedQuery, ?int $categoryId = null): Collection
    {
        $tokens = preg_split('/\s+/u', $expandedQuery) ?: [];
        $tokens = array_values(array_filter($tokens, function ($t) {
            return mb_strlen($t) >= 3;
        }));

        /** @var Builder $q */
        $q = Product::query();

        if ($tokens) {
            $q->where(function (Builder $q) use ($tokens) {
                foreach ($tokens as $token) {
                    $like = '%' . $token . '%';
                    $q->orWhere('search_index', 'LIKE', $like)
                      ->orWhere('title', 'LIKE', $like)
                      ->orWhere('category_path', 'LIKE', $like);
                }
            });
        }

        // TODO: якщо додамо category_id в БД — тут можна фільтрувати по ній.
        // if ($categoryId) {
        //     $q->where('category_id', $categoryId);
        // }

        // Зараз беремо максимум 100 кандидатів для скорингу
        return $q->limit(100)->get();
    }

    /**
     * Рахуємо скоринг для кожного товару.
     */
        /**
     * Рахуємо скоринг для кожного товару.
     * Тут поєднуємо:
     * - текстову релевантність (токени + точна фраза)
     * - доменні категорійні бонуси
     * - популярність за продажами / переглядами / додаванням у кошик
     */
    protected function scoreProducts(Collection $products, string $query): Collection
    {
        $queryTokens = $this->tokenize($query);

        return $products->map(function (Product $product) use ($queryTokens) {
            $haystack = mb_strtolower(trim(
                ($product->title ?? '') . ' ' .
                ($product->category_path ?? '') . ' ' .
                ($product->search_index ?? '')
            ));

            $productTokens = $this->tokenize($haystack);

            // 1) базовий скор за перетин токенів
            $intersect        = array_intersect($queryTokens, $productTokens);
            $tokenMatchScore  = count($intersect);
            // невеликий бонус за кількість збігів
            $tokenMatchScore += count($intersect) * 0.1;

            // 2) бонус за точну фразу
            $exactBonus = 0.0;
            if ($queryTokens) {
                $phrase = implode(' ', $queryTokens);
                if (str_contains($haystack, $phrase)) {
                    $exactBonus = 2.0;
                }
            }

            // 3) категорійні бонуси
            $categoryBonus = 0.0;
            $category      = mb_strtolower($product->category_path ?? '');

            // плитоноски
            if (
                str_contains($category, 'плитоноски') &&
                $this->containsOneOf($queryTokens, ['плитоноска', 'plate', 'carrier', 'бронік', 'бронежилет'])
            ) {
                $categoryBonus += 3.0;
            }

            // медичні підсумки / турнікет
            if (
                (str_contains($category, 'медичні підсумки') || str_contains($category, 'турнікети')) &&
                $this->containsOneOf($queryTokens, ['турнікет', 'турнікета', 'джгут'])
            ) {
                $categoryBonus += 3.0;
            }

            // пістолетні підсумки (глок)
            if (
                str_contains($category, 'пістолетні') &&
                $this->containsOneOf($queryTokens, ['glock', 'глок', 'пістолет'])
            ) {
                $categoryBonus += 2.0;
            }

            // 4) популярність (замовлення, додавання в кошик, перегляди)
            $ordersCount      = (int)($product->orders_count ?? 0);
            $viewsCount       = (int)($product->views_count ?? 0);
            $addedToCartCount = (int)($product->added_to_cart_count ?? 0);

            // даємо найбільшу вагу реальним замовленням
            $popularityRaw =
                  $ordersCount      * 3.0   // замовлення — найцінніше
                + $addedToCartCount * 1.0   // додали в кошик — сильний сигнал
                + $viewsCount       * 0.05; // перегляди — слабший сигнал

            // щоб популярність не перебивала повністю текст, трохи обрізаємо зверху
            $popularityScore = min($popularityRaw, 10.0);

            $score = $tokenMatchScore + $exactBonus + $categoryBonus + $popularityScore;

            return [
                'product' => $product,
                'score'   => $score,
            ];
        });
    }


    /**
     * Проста токенізація строки.
     */
    protected function tokenize(string $text): array
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
        $tokens = preg_split('/\s+/u', $text) ?: [];

        return array_values(array_filter($tokens));
    }

    /**
     * Перевіряє, чи масив токенів містить хоча б один з needle.
     */
    protected function containsOneOf(array $tokens, array $needles): bool
    {
        $set = array_flip($tokens);

        foreach ($needles as $needle) {
            if (isset($set[mb_strtolower($needle)])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Додаємо доменні синоніми тактичного спорядження до запиту.
     */
    protected function expandQueryWithDomainSynonyms(string $query, string $language = 'uk'): string
    {
        $q = mb_strtolower($query);

        $synonyms = [
            'плитоноска' => ['plate carrier', 'бронік', 'бронежилет', 'розвантажувальний жилет'],
            'бронік'     => ['плитоноска', 'plate carrier', 'бронежилет'],
            'турнікет'   => ['джгут', 'cat', 'tq'],
            'глок'       => ['glock', 'пістолетний підсумок'],
        ];

        $extra = [];

        foreach ($synonyms as $key => $list) {
            if (str_contains($q, $key)) {
                $extra = array_merge($extra, $list);
            }
        }

        if (empty($extra)) {
            return $q;
        }

        return trim($q . ' ' . implode(' ', $extra));
    }

    /**
     * Формат повернення під /api/chat.
     */
    public function normalizeProductForApi(Product $product): array
    {
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
        ];
    }
}
