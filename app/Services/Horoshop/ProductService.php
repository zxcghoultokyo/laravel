<?php

namespace App\Services\Horoshop;

use App\Models\ColorSynonym;
use App\Models\Product;
use App\Models\ProductAiIndex;
use App\Models\ProductSynonym;
use App\Models\ProductTag;
use App\Services\Ai\AiRouter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ProductService
{
    protected HoroshopClient $client;
    protected AiRouter $aiRouter;

    public function __construct(HoroshopClient $client, AiRouter $aiRouter)
    {
        $this->client   = $client;
        $this->aiRouter = $aiRouter;
    }

    /**
     * Синхронізація товарів із Horoshop у локальну БД.
     *
     * @param int $limit Максимальна кількість товарів за один запит
     */
    public function syncFromHoroshop(int $limit = 200): void
    {
        $offset = 0;

        do {
            $payload = [
                'expr'   => [
                    // можна звузити по parent, display_in_showcase тощо, якщо треба
                    'display_in_showcase' => 1,
                ],
                'limit'  => $limit,
                'offset' => $offset,
            ];

            Log::info('Horoshop sync request', $payload);

            $response = $this->client->request('catalog/export', $payload);

            if (($response['status'] ?? '') !== 'OK') {
                Log::warning('Horoshop sync status not OK', $response);
                break;
            }

            $products = Arr::get($response, 'response.products', []);

            if (empty($products)) {
                Log::info('Horoshop sync: no more products, break');
                break;
            }

            foreach ($products as $item) {
                $this->upsertProductFromHoroshop($item);
            }

            $offset += $limit;
        } while (true);
    }

    /**
     * Оновлюємо / створюємо локальний запис Product з даних Horoshop.
     */
    protected function upsertProductFromHoroshop(array $item): void
    {
        $article = $item['article'] ?? null;

        if (! $article) {
            return;
        }

        /** @var Product $product */
        $product = Product::query()->firstOrNew([
            'article' => $article,
        ]);

        $title = $item['title']['ua'] ?? $item['title']['ru'] ?? null;

        $product->fill([
            'article'             => $article,
            'parent_article'      => $item['parent_article'] ?? null,
            'title'               => $title,
            'title_json'          => $item['title'] ?? null,
            'price'               => $item['price'] ?? 0,
            'price_old'           => $item['price_old'] ?? 0,
            'category_path'       => $item['parent']['value'] ?? null,
            'slug'                => $item['slug'] ?? null,
            'link'                => $item['link'] ?? null,
            'images'              => $item['images'] ?? [],
            'raw'                 => $item,
            'presence'            => Arr::get($item, 'presence.value.ua')
                                        ?? Arr::get($item, 'presence.value.ru')
                                        ?? null,
            'quantity'            => $item['quantity'] ?? 0,
            'popularity'          => $item['popularity'] ?? 0,
            'we_recommended'      => (bool) ($item['we_recommended'] ?? false),
            'display_in_showcase' => (bool) ($item['display_in_showcase'] ?? false),
            'in_stock'            => $this->isInStock($item),
            'color'               => Arr::get($item, 'color.value.ua')
                                        ?? Arr::get($item, 'color.value.ru')
                                        ?? null,
        ]);

        $product->search_index = $this->buildSearchIndex($item, $product);

        $product->save();
    }

    protected function isInStock(array $item): bool
    {
        $presenceValue = Arr::get($item, 'presence.value.ua')
            ?? Arr::get($item, 'presence.value.ru')
            ?? '';

        $presenceValue = mb_strtolower((string) $presenceValue);

        if ($presenceValue === '') {
            return false;
        }

        $inStockPhrases = [
            'в наявності',
            'в наличии',
        ];

        foreach ($inStockPhrases as $phrase) {
            if (str_contains($presenceValue, $phrase)) {
                return true;
            }
        }

        $quantity = (int) ($item['quantity'] ?? 0);

        return $quantity > 0;
    }

    /**
     * Пошук товарів за частковим збігом у category_path.
     * Використовується сценаріями (такмед, шоломи, плити тощо).
     */
    public function searchByCategoryPathContains(string $needle, int $limit = 50): array
    {
        $needle = trim($needle);
        if ($needle === '') {
            return [];
        }

        $query = Product::query()
            ->where('display_in_showcase', true)
            ->where('in_stock', true)
            ->where('category_path', 'LIKE', '%' . $needle . '%')
            ->orderByDesc('popularity')
            ->limit($limit);

        $products = $query->get();

        return $products
            ->map(function (Product $product) {
                return $this->normalizeProductForApi($product);
            })
            ->all();
    }

    /**
     * Формуємо search_index — один великий рядок для LIKE-пошуку.
     */
    protected function buildSearchIndex(array $item, Product $product): string
    {
        $parts = [];

        $titleUa = Arr::get($item, 'title.ua', '');
        $titleRu = Arr::get($item, 'title.ru', '');

        $parts[] = $titleUa;
        $parts[] = $titleRu;

        $parts[] = Arr::get($item, 'parent.value', '');

        $brandUa = Arr::get($item, 'brand.value.ua', '');
        $brandRu = Arr::get($item, 'brand.value.ru', '');
        $parts[] = $brandUa;
        $parts[] = $brandRu;

        $descUa = Arr::get($item, 'description.ua', '');
        $descRu = Arr::get($item, 'description.ru', '');
        $parts[] = $descUa;
        $parts[] = $descRu;

        $colorUa = Arr::get($item, 'color.value.ua', '');
        $colorRu = Arr::get($item, 'color.value.ru', '');
        $parts[] = $colorUa;
        $parts[] = $colorRu;

        $characters = $item['characteristics'] ?? [];
        foreach ($characters as $key => $val) {
            if (is_array($val)) {
                $parts[] = implode(' ', $val);
            } else {
                $parts[] = (string) $val;
            }
        }

        $parts[] = (string) ($item['article'] ?? '');
        $parts[] = (string) ($item['parent_article'] ?? '');

        $searchIndex = implode(' ', array_filter($parts));

        return mb_strtolower($searchIndex);
    }

    /**
     * Розширення запиту з урахуванням доменних синонімів, колірних синонімів та тегів.
     */
    protected function expandQueryWithDomainSynonyms(string $query, string $language = 'uk'): string
    {
        $baseTokens = preg_split('/\s+/u', $query) ?: [];
        $baseTokens = array_values(array_filter($baseTokens, fn($t) => $t !== ''));

        $expandedTokens = $baseTokens;

        $synonyms = ProductSynonym::query()
            ->whereIn('phrase', $baseTokens)
            ->get();

        foreach ($synonyms as $syn) {
            $extra = $syn->synonyms ?? [];
            foreach ($extra as $word) {
                if (! in_array($word, $expandedTokens, true)) {
                    $expandedTokens[] = $word;
                }
            }
        }

        $colors = ColorSynonym::query()
            ->whereIn('phrase', $baseTokens)
            ->get();

        foreach ($colors as $colorSyn) {
            $canonicalColor = $colorSyn->color_normalized;
            if ($canonicalColor && ! in_array($canonicalColor, $expandedTokens, true)) {
                $expandedTokens[] = $canonicalColor;
            }
        }

        $tags = ProductTag::query()
            ->whereIn('tag', $baseTokens)
            ->get();

        foreach ($tags as $tag) {
            $extraTokens = $tag->extra_keywords ?? [];
            foreach ($extraTokens as $word) {
                if (! in_array($word, $expandedTokens, true)) {
                    $expandedTokens[] = $word;
                }
            }
        }

        $expanded = implode(' ', $expandedTokens);

        Log::info('ProductService::expandQueryWithDomainSynonyms', [
            'input'    => $query,
            'tokens'   => $baseTokens,
            'expanded' => $expanded,
        ]);

        return $expanded;
    }

    /**
     * Головний метод пошуку товарів по текстовому запиту.
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

        $expandedQuery = $this->expandQueryWithDomainSynonyms($normalized, $language);

        [$priceFilters, $queryWithoutPrice] = $this->extractPriceFilters($expandedQuery);

        if ($queryWithoutPrice !== '') {
            $expandedQuery = $queryWithoutPrice;
        }

        $candidates = $this->findCandidates($expandedQuery, $categoryId, $priceFilters);

        if ($candidates->isEmpty()) {
            Log::info('ProductService::searchByText no candidates found', [
                'expanded_query' => $expandedQuery,
                'price_filters'  => $priceFilters,
            ]);
            return [];
        }

        $scored = $this->scoreProducts($expandedQuery, $candidates);

        if ($scored->isEmpty()) {
            Log::info('ProductService::searchByText all candidates filtered out by score');
            return [];
        }

        $maxScore = $scored->max('score') ?? 0.0;

        $filtered = $scored->filter(function (array $row) use ($maxScore) {
            return $row['score'] >= $maxScore * 0.3;
        });

        if ($filtered->isEmpty()) {
            Log::info('ProductService::searchByText filtered collection empty after relative threshold');
            return [];
        }

        $sorted = $filtered->sortByDesc('score')->values();

        $deduped = $this->deduplicateProducts($sorted);

        return $deduped
            ->map(function (array $row) {
                /** @var Product $product */
                $product = $row['product'];

                return $this->normalizeProductForApi($product);
            })
            ->all();
    }

    /**
     * Видаляємо дублікати (якщо таке може бути).
     */
    protected function deduplicateProducts(Collection $scored): Collection
    {
        $seen = [];

        return $scored->filter(function (array $row) use (&$seen) {
            /** @var Product $product */
            $product = $row['product'];

            if (isset($seen[$product->article])) {
                return false;
            }

            $seen[$product->article] = true;

            return true;
        });
    }

    /**
     * Вирізаємо цінові обмеження з тексту.
     */
    protected function extractPriceFilters(string $query): array
    {
        $priceFilters = [
            'min' => null,
            'max' => null,
        ];

        $pattern = '/(?:до|менше|<)\s*(\d+)\s*(грн|uah|₴)?/ui';
        if (preg_match($pattern, $query, $m)) {
            $priceFilters['max'] = (float) $m[1];
            $query = str_replace($m[0], ' ', $query);
        }

        $pattern = '/(?:від|більше|>|\+)\s*(\d+)\s*(грн|uah|₴)?/ui';
        if (preg_match($pattern, $query, $m)) {
            $priceFilters['min'] = (float) $m[1];
            $query = str_replace($m[0], ' ', $query);
        }

        return [$priceFilters, trim($query)];
    }

    /**
     * Пошук кандидатів у локальній БД.
     */
    protected function findCandidates(string $expandedQuery, ?int $categoryId = null, array $priceFilters = []): Collection
    {
        $tokens = preg_split('/\s+/u', $expandedQuery) ?: [];
        $tokens = array_values(array_filter($tokens, function ($t) {
            return mb_strlen($t) >= 3;
        }));

        /** @var Builder $q */
        $q = Product::query()
            ->with('aiIndex');

        $q->where('display_in_showcase', true)
          ->where('in_stock', true);

        if ($categoryId !== null) {
            $q->where('category_id', $categoryId);
        }

        if (! empty($priceFilters['min'])) {
            $q->where('price', '>=', $priceFilters['min']);
        }
        if (! empty($priceFilters['max'])) {
            $q->where('price', '<=', $priceFilters['max']);
        }

        if ($tokens) {
            $q->where(function (Builder $q) use ($tokens) {
                foreach ($tokens as $token) {
                    $like = '%' . $token . '%';

                    $q->orWhere('search_index', 'LIKE', $like)
                      ->orWhere('title', 'LIKE', $like)
                      ->orWhere('category_path', 'LIKE', $like)
                      ->orWhere('color', 'LIKE', $like)
                      ->orWhereHas('aiIndex', function (Builder $ai) use ($like) {
                          $ai->where('product_type', 'LIKE', $like)
                             ->orWhere('ai_category', 'LIKE', $like)
                             ->orWhere('materials', 'LIKE', $like)
                             ->orWhere('standards', 'LIKE', $like)
                             ->orWhere('description', 'LIKE', $like);
                      });
                }
            });
        }

        $products = $q->get();

        Log::info('ProductService::findCandidates result count', [
            'count' => $products->count(),
        ]);

        return $products;
    }

    /**
     * Скоринг продуктів на основі запиту.
     */
    protected function scoreProducts(string $query, Collection $candidates): Collection
    {
        $query = mb_strtolower($query);
        $queryTokens = preg_split('/\s+/u', $query) ?: [];
        $queryTokens = array_values(array_filter($queryTokens, fn($t) => $t !== ''));

        $primaryNorm = $queryTokens[0] ?? '';

        $aiIndexMap = ProductAiIndex::query()
            ->whereIn('product_id', $candidates->pluck('id'))
            ->get()
            ->keyBy('product_id');

        $candidates = $candidates->map(function (Product $product) use ($aiIndexMap) {
            $aiIndex = $aiIndexMap->get($product->id);
            if ($aiIndex) {
                $product->setRelation('aiIndex', $aiIndex);
            }
            return $product;
        });

        $aiProductTypes = $this->detectProductTypes($query);

        $productTypeTokens = [];
        foreach ($aiProductTypes['product_types'] ?? [] as $pType) {
            $productTypeTokens[] = mb_strtolower($pType);
        }

        $mustHaveKeywords = [];
        foreach ($aiProductTypes['must_have_keywords'] ?? [] as $w) {
            $mustHaveKeywords[] = mb_strtolower($w);
        }

        return $candidates->map(function (Product $product) use (
            $query,
            $queryTokens,
            $productTypeTokens,
            $mustHaveKeywords,
            $primaryNorm
        ) {
            $title = mb_strtolower($product->title ?? '');
            $index = mb_strtolower($product->search_index ?? '');
            $cats  = mb_strtolower($product->category_path ?? '');

            $aiChunk = '';
            if ($product->relationLoaded('aiIndex') && $product->aiIndex) {
                $aiChunkParts = [
                    $product->aiIndex->product_type ?? '',
                    $product->aiIndex->ai_category ?? '',
                    $product->aiIndex->materials ?? '',
                    $product->aiIndex->standards ?? '',
                    $product->aiIndex->description ?? '',
                ];
                $aiChunk = mb_strtolower(implode(' ', array_filter($aiChunkParts)));
            }

            $haystack = $title . ' ' . $index . ' ' . $cats . ' ' . $aiChunk;

            $baseScore = 0.0;

            if ($primaryNorm !== '') {
                if (str_starts_with($title, $primaryNorm)) {
                    $baseScore += 25.0;
                } elseif (str_contains($title, $primaryNorm)) {
                    $baseScore += 15.0;
                }
            }

            $termMatches = 0;
            foreach ($queryTokens as $token) {
                if ($token === '') {
                    continue;
                }
                if (str_contains($haystack, $token)) {
                    $termMatches++;
                    $baseScore += 3.0;
                }
            }

            foreach ($productTypeTokens as $pType) {
                if ($pType !== '' && str_contains($haystack, $pType)) {
                    $baseScore += 12.0;
                }
            }

            $mustHavePenalty = 0.0;
            foreach ($mustHaveKeywords as $must) {
                if (! str_contains($haystack, $must)) {
                    $mustHavePenalty += 10.0;
                }
            }

            $equipmentPenalty = 0.0;
            if (! empty($productTypeTokens)) {
                $equipmentPenalty = $this->getAccessoryPenalty($haystack, $productTypeTokens);
            }

            $colorBonus      = $this->getColorMatchBonus($queryTokens, $product->color ?? null);
            $categoryBonus   = $this->getCategoryMatchBonus($queryTokens, $product->category_path ?? null);
            $popularityVal   = (int) ($product->popularity ?? 0);
            $popularityBonus = $this->getPopularityBonus($popularityVal);

            $titlePenalty = 0.0;
            if (mb_strlen($title) > 120 && $termMatches <= 1) {
                $titlePenalty = 5.0;
            }

            $score = $baseScore - $titlePenalty - $mustHavePenalty - $equipmentPenalty + $colorBonus + $categoryBonus + $popularityBonus;

            $flags = [
                'missing_product_type'    => false,
                'missing_must_have'       => ! empty($mustHaveKeywords) && ($mustHavePenalty > 0),
                'possible_accessory_only' => $equipmentPenalty > 0,
            ];

            return [
                'product' => $product,
                'score'   => $score,
                'flags'   => $flags,
            ];
        });
    }

    protected function getPopularityBonus(int $popularity): float
    {
        if ($popularity <= 0) {
            return 0.0;
        }

        if ($popularity >= 100) {
            return 15.0;
        }

        return min(15.0, $popularity / 10.0);
    }

    /**
     * Викликає AiRouter, щоб розібрати намір пошукового запиту.
     * Повертає масив:
     *  - product_types      => []
     *  - must_have_keywords => []
     *  - fallback_types     => []
     */
    protected function detectProductTypes(string $query): array
    {
        $result = $this->aiRouter->parseProductSearchIntent($query);

        if (! is_array($result)) {
            return [
                'product_types'      => [],
                'must_have_keywords' => [],
                'fallback_types'     => [],
            ];
        }

        $normalize = function ($value): array {
            if (! is_array($value)) {
                return [];
            }

            return array_values(array_filter(
                $value,
                fn($v) => is_string($v) && $v !== ''
            ));
        };

        return [
            'product_types'      => $normalize($result['product_types']      ?? []),
            'must_have_keywords' => $normalize($result['must_have_keywords'] ?? []),
            'fallback_types'     => $normalize($result['fallback_types']     ?? []),
        ];
    }

    protected function getAccessoryPenalty(string $haystack, array $productTypeTokens): float
    {
        $accessoryPatterns = [
            'cover', 'кобура', 'кавер', 'чохол', 'holder', 'кронштейн', 'adapt',
            'планка', 'підсумок', 'pouch', 'ремінець', 'strap',
            'карабін', 'панель', 'панелі', 'mount',
        ];

        $accessoryHit = false;
        foreach ($accessoryPatterns as $pattern) {
            if (mb_stripos($haystack, $pattern) !== false) {
                $accessoryHit = true;
                break;
            }
        }

        if (! $accessoryHit) {
            return 0.0;
        }

        $basePenalty = 10.0;

        foreach ($productTypeTokens as $pType) {
            if ($pType === '') {
                continue;
            }
            if (mb_stripos($haystack, $pType) !== false) {
                $basePenalty -= 5.0;
            }
        }

        return max(0.0, $basePenalty);
    }

    protected function getColorMatchBonus(array $queryTokens, ?string $productColor): float
    {
        if (! $productColor) {
            return 0.0;
        }

        $productColorNorm = mb_strtolower($productColor);

        $colorSynonyms = [
            'чорний' => ['чорний', 'чёрный', 'black', 'blk'],
            'оливковий' => ['оливковий', 'олива', 'olive', 'olive drab'],
            'зелений' => ['зелений', 'зелёный', 'green'],
            'койот' => ['койот', 'coyote', 'coy'],
            'мультикам' => ['мультикам', 'multicam', 'mc'],
        ];

        $bonus = 0.0;

        foreach ($queryTokens as $token) {
            $token = mb_strtolower($token);

            foreach ($colorSynonyms as $group => $syns) {
                if (in_array($token, $syns, true) && str_contains($productColorNorm, $group)) {
                    $bonus += 8.0;
                }
            }
        }

        return $bonus;
    }

    /**
     * Бонус за збіг по категорії (шоломи, плитоноски, куртки, такмед, плити тощо).
     */
    protected function getCategoryMatchBonus(array $queryTokens, ?string $categoryPath): float
    {
        if (! $categoryPath) {
            return 0.0;
        }

        $categoryNorm = mb_strtolower($categoryPath);
        $bonus = 0.0;

        $categoryHints = [
            'шолом' => [
                'шолом', 'шоломи', 'каска', 'каски',
                'helmet', 'helmets',
            ],
            'плитоноска' => [
                'плитоноска', 'плитоноски',
                'plate carrier', 'plate carriers',
                'розгрузка', 'розрузка',
            ],
            'куртка' => [
                'куртка', 'куртки', 'курточка',
                'jacket', 'jackets',
                'парка', 'softshell', 'soft shell', 'софтшел', 'софтшелл',
            ],
            'тактична медицина' => [
                'такмед', 'тактична медицина',
                'медуха', 'аптечка', 'аптечки',
                'ifak', 'іфак', 'медичка',
            ],
            'плити' => [
                'плита', 'плити',
                'бронеплита', 'бронеплити',
                'sapi', 'plate',
            ],
            'бронежилети' => [
                'бронік', 'бронежилет', 'броніки',
                'body armor', 'armor vest',
            ],
        ];

        foreach ($queryTokens as $token) {
            $token = mb_strtolower($token);

            foreach ($categoryHints as $catKey => $words) {
                if (in_array($token, $words, true) && str_contains($categoryNorm, $catKey)) {
                    $bonus += 10.0;
                }
            }
        }

        return $bonus;
    }

    /**
     * Маппінг тексту юзера → внутрішній category_key.
     */
    public function detectCategoryKeyFromText(string $text): ?string
    {
        $norm = mb_strtolower($text);
        $norm = str_replace(['зиова', 'зиимова'], 'зимова', $norm);

        // Турнікети
        if ($this->containsAny($norm, [
            'турнікет', 'турнікети', 'турникет', 'турникеты',
            'tourniquet', 'cat gen7', 'cat gen 7', 'cat-7',
        ])) {
            return 'tourniquets';
        }

        // Аптечки / IFAK
        if ($this->containsAny($norm, [
            'аптечка', 'аптечки', 'ifak', 'іфак',
            'медична сумка', 'тактична аптечка', 'тактична аптека',
            'медичка', 'такмед аптечка',
        ])) {
            return 'ifak_kits';
        }

        // Шоломи / каски
        if ($this->containsAny($norm, [
            'шолом', 'шоломи', 'каска', 'каски',
            'helmet', 'helmets',
        ])) {
            return 'helmets';
        }

        // Плитоноски / plate carrier / розгрузки
        if ($this->containsAny($norm, [
            'плитоноска', 'плитоноски',
            'plate carrier', 'plate-carrier',
            'розгрузка', 'розрузка',
            'розвантажувальний жилет',
        ])) {
            return 'plate_carriers';
        }

        // Плити / бронеплити
        if ($this->containsAny($norm, [
            'бронеплита', 'бронеплити', 'плита', 'плити',
            'sapi', 'esapi', 'plate', 'plates',
        ])) {
            return 'plates';
        }

        // Зимові куртки / парки / фліс / термуха → холодний одяг
        if ($this->containsAny($norm, [
            'зимова куртка', 'зимову куртку', 'зимові куртки',
            'куртка зимова', 'тепла куртка', 'куртка для зими',
            'парка', 'парки',
            'softshell', 'софтшел', 'софтшелл',
            'фліс', 'фліска', 'fleece',
            'термуха', 'термобілизна', 'термобелье', 'thermal underwear', 'thermal',
        ])) {
            return 'cold_weather_jackets';
        }

        // Тактична медицина в цілому
        if ($this->containsAny($norm, [
            'такмед', 'тактична медицина', 'tactical medicine',
            'медичне спорядження', 'медичне споряження',
        ])) {
            return 'tactical_medicine';
        }

        return null;
    }

    protected function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle === '') {
                continue;
            }
            if (mb_stripos($haystack, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Пошук товарів по внутрішньому ключу категорії (tourniquets, helmets, etc.).
     */
    public function searchByCategoryKey(string $categoryKey, int $limit = 3, array $priceFilters = []): array
    {
        $categoryKey = mb_strtolower($categoryKey);

        /** @var Builder $q */
        $q = Product::query()
            ->where('display_in_showcase', true)
            ->where('in_stock', true);

        switch ($categoryKey) {
            case 'tourniquets':
                $q->where(function (Builder $q) {
                    $q->where('category_path', 'LIKE', '%турнік%')
                      ->orWhere('category_path', 'LIKE', '%турнікет%')
                      ->orWhere('category_path', 'LIKE', '%турникет%')
                      ->orWhere('category_path', 'LIKE', '%тактична медицина%')
                      ->orWhere('title', 'LIKE', '%турнікет%')
                      ->orWhere('search_index', 'LIKE', '%tourniquet%');
                });
                break;

            case 'ifak_kits':
                $q->where(function (Builder $q) {
                    $q->where('category_path', 'LIKE', '%аптечк%')
                      ->orWhere('category_path', 'LIKE', '%медичн%')
                      ->orWhere('title', 'LIKE', '%аптечк%')
                      ->orWhere('search_index', 'LIKE', '%ifak%')
                      ->orWhere('search_index', 'LIKE', '%medic%');
                });
                break;

            case 'helmets':
                $q->where(function (Builder $q) {
                    $q->where('category_path', 'LIKE', '%шолом%')
                      ->orWhere('category_path', 'LIKE', '%каска%')
                      ->orWhere('title', 'LIKE', '%шолом%')
                      ->orWhere('title', 'LIKE', '%каска%')
                      ->orWhere('search_index', 'LIKE', '%helmet%');
                });
                break;

            case 'plate_carriers':
                $q->where(function (Builder $q) {
                    $q->where('category_path', 'LIKE', '%плитоноск%')
                      ->orWhere('category_path', 'LIKE', '%розгруз%')
                      ->orWhere('title', 'LIKE', '%плитоноск%')
                      ->orWhere('title', 'LIKE', '%розгруз%')
                      ->orWhere('search_index', 'LIKE', '%plate carrier%');
                });
                break;

            case 'plates':
                $q->where(function (Builder $q) {
                    $q->where('category_path', 'LIKE', '%плити%')
                      ->orWhere('category_path', 'LIKE', '%бронеплити%')
                      ->orWhere('category_path', 'LIKE', '%бронезахист%')
                      ->orWhere('title', 'LIKE', '%плита%')
                      ->orWhere('title', 'LIKE', '%бронеплит%')
                      ->orWhere('search_index', 'LIKE', '%sapi%')
                      ->orWhere('search_index', 'LIKE', '%plate%');
                });
                break;

            case 'cold_weather_jackets':
                $q->where(function (Builder $q) {
                    $q->where('category_path', 'LIKE', '%куртк%')
                      ->orWhere('category_path', 'LIKE', '%парка%')
                      ->orWhere('category_path', 'LIKE', '%зим%')
                      ->orWhere('title', 'LIKE', '%куртк%')
                      ->orWhere('title', 'LIKE', '%парка%')
                      ->orWhere('search_index', 'LIKE', '%softshell%')
                      ->orWhere('search_index', 'LIKE', '%фліс%')
                      ->orWhere('search_index', 'LIKE', '%fleece%')
                      ->orWhere('search_index', 'LIKE', '%термобілизн%')
                      ->orWhere('search_index', 'LIKE', '%термуха%');
                });
                break;

            case 'tactical_medicine':
                $q->where(function (Builder $q) {
                    $q->where('category_path', 'LIKE', '%тактична медицина%')
                      ->orWhere('category_path', 'LIKE', '%медичн%')
                      ->orWhere('category_path', 'LIKE', '%медичні підсумки%')
                      ->orWhere('search_index', 'LIKE', '%турнікет%')
                      ->orWhere('search_index', 'LIKE', '%ifak%')
                      ->orWhere('search_index', 'LIKE', '%medic%');
                });
                break;

            default:
                return [];
        }

        if (! empty($priceFilters['min'])) {
            $q->where('price', '>=', $priceFilters['min']);
        }
        if (! empty($priceFilters['max'])) {
            $q->where('price', '<=', $priceFilters['max']);
        }

        $q->orderByDesc('popularity')->limit($limit);

        $products = $q->get();

        return $products
            ->map(fn (Product $product) => $this->normalizeProductForApi($product))
            ->all();
    }

    /**
     * Нормалізує модель Product до простого масиву для API-відповіді чату.
     */
    public function normalizeProductForApi(Product $product): array
    {
        return [
            'id'             => $product->id,
            'article'        => $product->article,
            'parent_article' => $product->parent_article,
            'title'          => $product->title,
            'title_json'     => $product->title_json,
            'price'          => (float) ($product->price ?? 0),
            'price_old'      => (float) ($product->price_old ?? 0),
            'category_path'  => $product->category_path,
            'slug'           => $product->slug,
            'link'           => $product->link,
            'images'         => $product->images ?? [],
            'presence'       => $product->presence,
            'quantity'       => (int) ($product->quantity ?? 0),
            'in_stock'       => (bool) ($product->in_stock ?? false),
            'color'          => $product->color,
            'popularity'     => (int) ($product->popularity ?? 0),
        ];
    }
}
