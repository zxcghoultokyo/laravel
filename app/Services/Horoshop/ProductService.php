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

        // 1) Робимо базову нормалізацію запиту без доменних синонімів.
        $expandedQuery = $normalized;

        // 2) Дістаємо цінові фільтри з тексту ("до 5 тис", "від 3 до 7 тис" тощо)
        $priceFilters = $this->extractPriceFiltersFromQuery($normalized);

        // 3) Шукаймо кандидатів у локальній БД з урахуванням:
        //    - display_in_showcase = 1
        //    - in_stock = 1
        //    - min_price / max_price (якщо витягнулись з запиту)
        $candidates = $this->findCandidates($expandedQuery, $categoryId, $priceFilters);

        if ($candidates->isEmpty()) {
            Log::info('ProductService::searchByText no candidates found', [
                'expanded_query' => $expandedQuery,
                'price_filters'  => $priceFilters,
            ]);

            return [];
        }

        // 4) Рахуємо скор для кожного товару
        $scored = $this->scoreProducts($candidates, $expandedQuery);

        if ($scored->isEmpty()) {
            Log::info('ProductService::searchByText all candidates filtered out by score');
            return [];
        }

        // 5) Фільтруємо за відносним порогом релевантності
        $maxScore = $scored->max('score') ?? 0.0;

        if ($maxScore < 1.0) {
            Log::info('ProductService::searchByText max score too low', [
                'max_score'      => $maxScore,
                'price_filters'  => $priceFilters,
            ]);
            return [];
        }

        // залишаємо товари, які набрали >= 50% від максимального скору
        $filtered = $scored->filter(function (array $row) use ($maxScore) {
            return $row['score'] >= $maxScore * 0.5;
        });

        if ($filtered->isEmpty()) {
            Log::info('ProductService::searchByText filtered collection empty after threshold', [
                'max_score'     => $maxScore,
                'price_filters' => $priceFilters,
            ]);
            return [];
        }

        // 6) Сортуємо та обрізаємо до топ-N
        $sorted = $filtered
            ->sortByDesc('score')
            ->values()
            ->take(30); // 30 кандидатів більш ніж достатньо для AiRecommender

        // 7) Нормалізуємо до формату для API
        return $sorted
            ->map(function (array $row) {
                /** @var Product $product */
                $product = $row['product'];

                return $this->normalizeProductForApi($product);
            })
            ->all();
    }

    /**
     * Пошук кандидатів у локальній БД (БЕЗ доменних хардкодів).
     *
     * Тут застосовуються жорсткі бізнес-правила:
     *  - показуємо тільки те, що:
     *      display_in_showcase = 1
     *      in_stock = 1
     *      [опційно] price між min_price / max_price
     */
    protected function findCandidates(string $expandedQuery, ?int $categoryId = null, array $priceFilters = []): Collection
    {
        $tokens = preg_split('/\s+/u', $expandedQuery) ?: [];
        $tokens = array_values(array_filter($tokens, function ($t) {
            return mb_strlen($t) >= 3;
        }));

        /** @var Builder $q */
        $q = Product::query();

        // ЖОРСТКІ БІЗНЕС-ПРАВИЛА:
        $q->where('display_in_showcase', true)
          ->where('in_stock', true);

        // Цінові фільтри (запит типу "бронік до 5 тис", "рюкзак від 3к", "від 3000 до 8000" тощо)
        if (isset($priceFilters['min_price'])) {
            $q->where('price', '>=', (int) $priceFilters['min_price']);
        }

        if (isset($priceFilters['max_price'])) {
            $q->where('price', '<=', (int) $priceFilters['max_price']);
        }

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

        return $q->limit(100)->get();
    }

    /**
     * Розбираємо з тексту бюджети:
     *   - "до 5тис", "до 5 тис", "до 5000", "до 5к" → max_price = 5000
     *   - "від 3 тис" / "від 3000"                 → min_price = 3000
     *   - "від 3 до 7 тис"                        → min_price = 3000, max_price = 7000
     */
    protected function extractPriceFiltersFromQuery(string $query): array
    {
        $q = mb_strtolower($query);

        $result = [
            // 'min_price' => int
            // 'max_price' => int
        ];

        // Шукаємо всі числа з можливими суфіксами "тис", "k", "к"
        // приклади: "5тис", "5 тис", "5k", "5к", "5000"
        $pattern = '/(\d+)\s*(тис|тыс|k|к)?/u';
        preg_match_all($pattern, $q, $matches, PREG_OFFSET_CAPTURE);

        if (empty($matches[0])) {
            return $result;
        }

        $numbers = [];

        foreach ($matches[0] as $index => $match) {
            $fullMatch = $match[0];
            $pos       = $match[1];

            $numStr  = $matches[1][$index][0] ?? '';
            $suffix  = $matches[2][$index][0] ?? '';
            $number  = (int) $numStr;

            if ($number <= 0) {
                continue;
            }

            $multiplier = 1;

            if (in_array($suffix, ['тис', 'тыс', 'k', 'к'], true)) {
                $multiplier = 1000;
            }

            $value = $number * $multiplier;

            $numbers[] = [
                'value' => $value,
                'pos'   => $pos,
            ];
        }

        if (empty($numbers)) {
            return $result;
        }

        // допоміжка: знайти ключове слово ("до", "від") перед числом
        $findWordBefore = function (string $needle, int $pos) use ($q): bool {
            // дивимось у вікні ~20 символів перед числом
            $start = max(0, $pos - 20);
            $chunk = mb_substr($q, $start, $pos - $start);

            return str_contains($chunk, $needle);
        };

        // ВАРІАНТ 1: "від X до Y ..."
        if (count($numbers) >= 2) {
            $first = $numbers[0];
            $second = $numbers[1];

            if ($findWordBefore('від', $first['pos']) && $findWordBefore('до', $second['pos'])) {
                $result['min_price'] = $first['value'];
                $result['max_price'] = $second['value'];

                return $result;
            }
        }

        // ВАРІАНТ 2: один діапазон "до X ..." або "від X ..."
        foreach ($numbers as $n) {
            if ($findWordBefore('до', $n['pos'])) {
                $result['max_price'] = $n['value'];
            }

            if ($findWordBefore('від', $n['pos'])) {
                $result['min_price'] = $n['value'];
            }
        }

        return $result;
    }

    /**
     * Рахуємо скоринг для кожного товару.
     *
     * Поєднуємо:
     *  - текстову релевантність (токени + точна фраза)
     *  - популярність (orders/views/added_to_cart + popularity з Horoshop)
     *  - "ми рекомендуємо" (we_recommended)
     *  - БОНУС за збіг кольору (але БЕЗ доменного словника)
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
                + $hsPopularity     * 0.7   // популярність з Horoshop
                + $viewsCount       * 0.03; // перегляди — слабший сигнал

            $popularityScore = min($popularityRaw, 12.0);

            // 4) "ми рекомендуємо"
            $recommendedBonus = $product->we_recommended ? 2.5 : 0.0;

            // 5) БОНУС за збіг кольору (універсальний, без словників)
            $colorBonus = $this->getColorMatchBonus($queryTokens, $product->color);

            $score = $tokenMatchScore + $exactBonus + $popularityScore + $recommendedBonus + $colorBonus;

            return [
                'product' => $product,
                'score'   => $score,
            ];
        });
    }

    /**
     * Додаємо бонус, якщо слово з запиту схоже на значення кольору з каталогу.
     * Працює для будь-якого домену (іграшки, одяг, тактичка і т.д.).
     */
    protected function getColorMatchBonus(array $queryTokens, ?string $productColor): float
    {
        if (!$productColor) {
            return 0.0;
        }

        $productColorNorm = $this->normalizeWord($productColor);
        if ($productColorNorm === '') {
            return 0.0;
        }

        $bonus = 0.0;

        foreach ($queryTokens as $token) {
            $tokenNorm = $this->normalizeWord($token);

            if (mb_strlen($tokenNorm) < 3) {
                continue;
            }

            // простий збіг: "чорний" vs "чорна", "black" vs "black", "pink" vs "pink"
            if (
                str_contains($productColorNorm, $tokenNorm) ||
                str_contains($tokenNorm, $productColorNorm)
            ) {
                $bonus += 3.0;
                break;
            }
        }

        return $bonus;
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
     * Дуже проста "лематизація": прибираємо типові закінчення,
     * щоб "чорний" і "чорна" зводились до "чорн".
     * Це не ідеально, але працює для UA/RU кольорів/прикметників
     * і не прив'язане до конкретного домену.
     */
    protected function normalizeWord(string $word): string
    {
        $w = mb_strtolower(trim($word));

        // прибираємо всі символи, крім букв/цифр
        $w = preg_replace('/[^\p{L}\p{N}]+/u', '', $w);

        // типові прикметникові закінчення (дуже грубо)
        $w = preg_replace('/(ий|ій|ый|ой|ая|ое|ого|ому|им|их|ої|ою|а|я|е|і)$/u', '', $w);

        return $w;
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

            // Поля наявності / пріоритизації
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
