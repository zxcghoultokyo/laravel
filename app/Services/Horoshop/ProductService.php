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
     * Головний метод пошуку товарів по текстовому запиту.
     *
     * Використовується ChatController'ом та DebugProductsController'ом.
     *
     * @param  string      $rawQuery
     * @param  int|null    $categoryId   (поки не використовується, залишено для майбутнього)
     * @param  string      $language
     * @return array       Масив нормалізованих товарів для API
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

        // 1) додаємо доменні синоніми, щоб ловити "бронік", "турнікет", "глок" тощо
        $expandedQuery = $this->expandQueryWithDomainSynonyms($normalized, $language);

        // 2) шукаємо кандидатів у локальній БД
        $candidates = $this->findCandidates($expandedQuery, $categoryId);

        if ($candidates->isEmpty()) {
            Log::info('ProductService::searchByText no candidates found', [
                'expanded_query' => $expandedQuery,
            ]);

            return [];
        }

        // 3) рахуємо скор для кожного товару
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
        $sorted = $filtered
            ->sortByDesc('score')
            ->values()
            ->take(30); // 30 кандидатів більш ніж достатньо для AiRecommender

        // 6) нормалізуємо до формату для API
        return $sorted
            ->map(function (array $row) {
                /** @var Product $product */
                $product = $row['product'];

                return $this->normalizeProductForApi($product);
            })
            ->all();
    }

    /**
     * Пошук кандидатів у локальній БД (БЕЗ хардкоду по категоріях).
     *
     * Тут застосовуються жорсткі бізнес-правила:
     *  - показуємо тільки те, що:
     *      display_in_showcase = 1
     *      in_stock = 1
     */
    protected function findCandidates(string $expandedQuery, ?int $categoryId = null): Collection
    {
        $tokens = preg_split('/\s+/u', $expandedQuery) ?: [];
        $tokens = array_values(array_filter($tokens, function ($t) {
            return mb_strlen($t) >= 3;
        }));

        /** @var Builder $q */
        $q = Product::query();

        // ЖОРСТКІ БІЗНЕС-ПРАВИЛА:
        // - показуємо тільки те, що реально продається на сайті
        $q->where('display_in_showcase', true)
          ->where('in_stock', true);

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
     *
     * Тут поєднуємо:
     *  - текстову релевантність (токени + точна фраза)
     *  - популярність (orders/views/added_to_cart + popularity з Horoshop)
     *  - "ми рекомендуємо" (we_recommended)
     *
     * БЕЗ жорсткого if по категоріях — менше хардкоду, більше місця для AI.
     */
    protected function scoreProducts(Collection $products, string $query): Collection
    {
        $queryTokens = $this->tokenize($query);
        $queryPhrase = implode(' ', $queryTokens);

        return $products->map(function (Product $product) use ($queryTokens, $queryPhrase) {
            $haystack = mb_strtolower(trim(
                ($product->title ?? '') . ' ' .
                ($product->category_path ?? '') . ' ' .
                ($product->search_index ?? '') . ' ' .
                ($product->color ?? '')
            ));

            $productTokens = $this->tokenize($haystack);

            // 1) базовий скор за перетин токенів
            $intersect       = array_intersect($queryTokens, $productTokens);
            $tokenMatchScore = count($intersect);

            // невеликий додатковий бонус за кількість збігів
            $tokenMatchScore += count($intersect) * 0.15;

            // 2) бонус за точну фразу
            $exactBonus = 0.0;
            if ($queryPhrase !== '' && str_contains($haystack, $queryPhrase)) {
                $exactBonus = 2.0;
            }

            // 3) популярність (замовлення, додавання в кошик, перегляди, popularity з Horoshop)
            $ordersCount      = (int)($product->orders_count ?? 0);
            $viewsCount       = (int)($product->views_count ?? 0);
            $addedToCartCount = (int)($product->added_to_cart_count ?? 0);
            $hsPopularity     = (int)($product->popularity ?? 0);

            $popularityRaw =
                  $ordersCount      * 3.0   // замовлення — найцінніше
                + $addedToCartCount * 1.2   // додали в кошик — сильний сигнал
                + $hsPopularity     * 0.7   // popularність з Horoshop
                + $viewsCount       * 0.03; // перегляди — слабший сигнал

            // щоб популярність не перебивала повністю текст, трохи обрізаємо зверху
            $popularityScore = min($popularityRaw, 12.0);

            // 4) "ми рекомендуємо"
            $recommendedBonus = $product->we_recommended ? 2.5 : 0.0;

            $score = $tokenMatchScore + $exactBonus + $popularityScore + $recommendedBonus;

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
     * (Зараз майже не використовується, але залишаємо на майбутнє.)
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

            // НОВЕ: поля наявності / пріоритизації
            'display_in_showcase'  => (bool) $product->display_in_showcase,
            'in_stock'             => (bool) $product->in_stock,
            'presence'             => $product->presence,
            'quantity'             => $product->quantity,
            'popularity'           => $product->popularity,
            'we_recommended'       => (bool) $product->we_recommended,
            'color'                => $product->color,
        ];
    }
}
