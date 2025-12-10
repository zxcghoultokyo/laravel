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

        // 1) Базова нормалізація запиту
        $expandedQuery = $normalized;

        // 2) Цінові фільтри з тексту
        $priceFilters = $this->extractPriceFiltersFromQuery($normalized);

        // 3) Шукаймо кандидатів у локальній БД
        $candidates = $this->findCandidates($expandedQuery, $categoryId, $priceFilters);

        if ($candidates->isEmpty()) {
            Log::info('ProductService::searchByText no candidates found', [
                'expanded_query' => $expandedQuery,
                'price_filters'  => $priceFilters,
            ]);
            return [];
        }

        // 4) Скоримо (AI intent + хард-правила + primary token)
        $scored = $this->scoreProducts($expandedQuery, $candidates);

        if ($scored->isEmpty()) {
            Log::info('ProductService::searchByText all candidates filtered out by score');
            return [];
        }

        // 5) Відносний поріг релевантності
        $maxScore = $scored->max('score') ?? 0.0;

        if ($maxScore < 1.0) {
            Log::info('ProductService::searchByText max score too low', [
                'max_score'      => $maxScore,
                'price_filters'  => $priceFilters,
            ]);
            return [];
        }

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

        // 6) Сортуємо
        $sorted = $filtered
            ->sortByDesc('score')
            ->values()
            ->take(50); // беремо трохи з запасом до дедупу

        // 7) ДЕДУПЛІКАЦІЯ по parent_article / title (розміри → один товар)
        $deduped = $this->deduplicateProducts($sorted);

        // 8) Обрізаємо до топ-N для API
        $top = $deduped->take(30);

        // 9) Нормалізуємо для API
        return $top
            ->map(function (array $row) {
                /** @var Product $product */
                $product = $row['product'];
                return $this->normalizeProductForApi($product);
            })
            ->all();
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

        // ЖОРСТКІ БІЗНЕС-ПРАВИЛА:
        $q->where('display_in_showcase', true)
          ->where('in_stock', true);

        // Цінові фільтри
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
                      ->orWhere('category_path', 'LIKE', $like)
                      ->orWhereHas('aiIndex', function (Builder $ai) use ($like) {
                          $ai->where('product_type', 'LIKE', $like)
                             ->orWhere('ai_category', 'LIKE', $like)
                             ->orWhere('materials', 'LIKE', $like)
                             ->orWhere('standards', 'LIKE', $like)
                             ->orWhere('slang', 'LIKE', $like)
                             ->orWhere('keywords', 'LIKE', $like)
                             ->orWhere('usage', 'LIKE', $like);
                      });
                }
            });
        }

        // if ($categoryId) { ... } // залишаємо на майбутнє

        return $q->limit(150)->get();
    }

    /**
     * Парсимо цінові фільтри з тексту.
     */
    protected function extractPriceFiltersFromQuery(string $query): array
    {
        $q = mb_strtolower($query);

        $result = [];

        $pattern = '/(\d+)\s*(тис|тыс|k|к)?/u';
        preg_match_all($pattern, $q, $matches, PREG_OFFSET_CAPTURE);

        if (empty($matches[0])) {
            return $result;
        }

        $numbers = [];

        foreach ($matches[0] as $index => $match) {
            $pos    = $match[1];
            $numStr = $matches[1][$index][0] ?? '';
            $suffix = $matches[2][$index][0] ?? '';
            $number = (int) $numStr;

            if ($number <= 0) {
                continue;
            }

            $multiplier = in_array($suffix, ['тис', 'тыс', 'k', 'к'], true) ? 1000 : 1;
            $value      = $number * $multiplier;

            $numbers[] = [
                'value' => $value,
                'pos'   => $pos,
            ];
        }

        if (empty($numbers)) {
            return $result;
        }

        $findWordBefore = function (string $needle, int $pos) use ($q): bool {
            $start = max(0, $pos - 20);
            $chunk = mb_substr($q, $start, $pos - $start);
            return str_contains($chunk, $needle);
        };

        // "від X до Y"
        if (count($numbers) >= 2) {
            $first  = $numbers[0];
            $second = $numbers[1];

            if ($findWordBefore('від', $first['pos']) && $findWordBefore('до', $second['pos'])) {
                $result['min_price'] = $first['value'];
                $result['max_price'] = $second['value'];
                return $result;
            }
        }

        // окремо "до X" / "від X"
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
     * Скоринг для кожного товару.
     */
    protected function scoreProducts(string $query, Collection $products): Collection
    {
        $query       = mb_strtolower(trim($query));
        $queryTokens = $this->tokenize($query);
        $primaryToken = $queryTokens[0] ?? null;
        $primaryNorm  = $primaryToken ? $this->normalizeWord($primaryToken) : null;

        // 1. AI intent
        $intent = [];
        try {
            /** @var \App\Services\Ai\AiRouter $aiRouter */
            $aiRouter = app(\App\Services\Ai\AiRouter::class);
            $intent   = $aiRouter->parseProductSearchIntent($query);
        } catch (\Throwable $e) {
            Log::warning('ProductService::scoreProducts intent parse failed: ' . $e->getMessage());
        }

        $productTypeTokens = $this->extractProductTypeTokensFromIntent($intent);
        $mustHaveKeywords  = $this->extractMustHaveKeywordsFromIntent($intent);

        $scored = $products->map(function (Product $product) use (
            $query,
            $queryTokens,
            $productTypeTokens,
            $mustHaveKeywords,
            $primaryNorm
        ) {
            $title = mb_strtolower($product->title ?? '');
            $index = mb_strtolower($product->search_index ?? '');
            $cats  = mb_strtolower($product->category_path ?? '');

            // AI-індекс → haystack
            $aiChunk = '';
            if ($product->relationLoaded('aiIndex') && $product->aiIndex) {
                $aiChunkParts = [
                    $product->aiIndex->product_type ?? '',
                    $product->aiIndex->ai_category ?? '',
                    $product->aiIndex->materials ?? '',
                    $product->aiIndex->standards ?? '',
                    $product->aiIndex->slang ?? '',
                    $product->aiIndex->keywords ?? '',
                    $product->aiIndex->usage ?? '',
                ];
                $aiChunk = mb_strtolower(implode(' ', $aiChunkParts));
            }

            $haystack     = $title . ' ' . $index . ' ' . $cats . ' ' . $aiChunk;
            $haystackNorm = $this->normalizeTextForMatching($haystack);

            $baseScore   = 0.0;
            $termMatches = 0;

            // базовий лексичний скорінг
            foreach ($queryTokens as $token) {
                if ($token === '') {
                    continue;
                }

                if (mb_strpos($haystack, $token) !== false) {
                    $termMatches++;

                    if (mb_strpos($title, $token) !== false) {
                        $baseScore += 5;
                    } else {
                        $baseScore += 2;
                    }
                }
            }

            if ($termMatches === 0) {
                $baseScore -= 10;
            }

            // бонус за колір
            $colorBonus = $this->getColorMatchBonus($queryTokens, $product->color ?? null);

            // штраф за “стіну тексту” без збігів
            $titlePenalty = 0.0;
            if (mb_strlen($title) > 120 && $termMatches <= 1) {
                $titlePenalty = 5.0;
            }

            $score = $baseScore - $titlePenalty + $colorBonus;

            $flags = [
                'missing_product_type'   => false,
                'missing_must_keywords'  => false,
                'missing_primary_token'  => false,
            ];

            // a) product_types з intent-а
            if (! empty($productTypeTokens)) {
                $hasAnyTypeToken = false;

                foreach ($productTypeTokens as $token) {
                    if ($token === '') {
                        continue;
                    }
                    if (mb_strpos($haystackNorm, $this->normalizeWord($token)) !== false) {
                        $hasAnyTypeToken = true;
                        break;
                    }
                }

                if (! $hasAnyTypeToken) {
                    $flags['missing_product_type'] = true;
                    $score -= 20;
                }
            }

            // b) must_have_keywords
            if (! empty($mustHaveKeywords)) {
                $missing = false;

                foreach ($mustHaveKeywords as $kw) {
                    if ($kw === '') {
                        continue;
                    }

                    $kwNorm = $this->normalizeWord($kw);

                    if ($kwNorm && mb_strpos($haystackNorm, $kwNorm) === false) {
                        $missing = true;
                        break;
                    }
                }

                if ($missing) {
                    $flags['missing_must_keywords'] = true;
                    $score -= 50;
                }
            }

            // c) PRIMARY TOKEN (типу "плитоноска", "футболка", "каска")
            if ($primaryNorm && mb_strlen($primaryNorm) >= 4) {
                if (mb_strpos($haystackNorm, $primaryNorm) === false) {
                    $flags['missing_primary_token'] = true;
                    $score -= 25;
                }
            }

            return [
                'product' => $product,
                'score'   => $score,
                'flags'   => $flags,
            ];
        });

        // Якщо є товари, що проходять по product_type – ріжемо всі, де його нема
        $hasStrictTypeMatches = $scored->first(function ($row) {
            return empty($row['flags']['missing_product_type']) && $row['score'] > 0;
        });

        if ($hasStrictTypeMatches) {
            $scored = $scored->filter(function ($row) {
                return empty($row['flags']['missing_product_type']);
            });
        }

        // Якщо є товари з must_have_keywords – ріжемо ті, де вони відсутні
        $hasStrictKeywordMatches = $scored->first(function ($row) {
            return empty($row['flags']['missing_must_keywords']) && $row['score'] > 0;
        });

        if ($hasStrictKeywordMatches) {
            $scored = $scored->filter(function ($row) {
                return empty($row['flags']['missing_must_keywords']);
            });
        }

        // Якщо є товари з primary token – ріжемо ті, де його немає
        $hasPrimaryMatches = $scored->first(function ($row) {
            return empty($row['flags']['missing_primary_token']) && $row['score'] > 0;
        });

        if ($hasPrimaryMatches) {
            $scored = $scored->filter(function ($row) {
                return empty($row['flags']['missing_primary_token']);
            });
        }

        // Фінальний фільтр
        $scored = $scored
            ->filter(function ($row) {
                return $row['score'] > -30;
            })
            ->sortByDesc('score')
            ->values();

        return $scored;
    }

    /**
     * Дедуплікація: групуємо товари по parent_article / title.
     * Якщо в групі кілька варіантів (розміри) — залишаємо з найбільшим score.
     */
    protected function deduplicateProducts(Collection $scored): Collection
    {
        $groups = [];

        foreach ($scored as $row) {
            /** @var Product $product */
            $product = $row['product'];

            $parentArticle = null;
            try {
                $parentArticle = data_get($product->raw ?? [], 'parent_article');
            } catch (\Throwable $e) {
                $parentArticle = null;
            }

            if ($parentArticle) {
                $groupKey = 'parent:' . $parentArticle;
            } else {
                $titleKey = mb_strtolower(preg_replace('/\s+/u', ' ', trim($product->title ?? '')));
                $groupKey = 'title:' . $titleKey;
            }

            if (! isset($groups[$groupKey])) {
                $groups[$groupKey] = $row;
            } else {
                if ($row['score'] > $groups[$groupKey]['score']) {
                    $groups[$groupKey] = $row;
                }
            }
        }

        return collect(array_values($groups))
            ->sortByDesc('score')
            ->values();
    }

    /**
     * Нормалізація тексту для пошуку (прибираємо сміття, лематизуємо слова).
     */
    protected function normalizeTextForMatching(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
        $parts = preg_split('/\s+/u', $text) ?: [];
        $parts = array_map(function ($w) {
            return $this->normalizeWord($w);
        }, $parts);

        return trim(implode(' ', array_filter($parts)));
    }

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

    protected function extractProductTypeTokensFromIntent(array $intent): array
    {
        $types  = $intent['product_types'] ?? [];
        $result = [];

        foreach ($types as $type) {
            $type = mb_strtolower(trim($type));
            if ($type === '') {
                continue;
            }

            $result[] = $type;

            if (str_contains($type, ' ')) {
                $result[] = str_replace(' ', '', $type);
            }
        }

        return array_values(array_unique($result));
    }

    protected function extractMustHaveKeywordsFromIntent(array $intent): array
    {
        $keywords = $intent['must_have_keywords'] ?? [];
        $result   = [];

        foreach ($keywords as $kw) {
            $kw = mb_strtolower(trim($kw));
            if ($kw === '') {
                continue;
            }

            $result[] = $kw;
        }

        return array_values(array_unique($result));
    }

    protected function tokenize(string $text): array
    {
        $text   = mb_strtolower($text);
        $text   = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
        $tokens = preg_split('/\s+/u', $text) ?: [];

        return array_values(array_filter($tokens));
    }

    /**
     * Дуже проста нормалізація слова.
     */
    protected function normalizeWord(string $word): string
    {
        $w = mb_strtolower(trim($word));
        $w = preg_replace('/[^\p{L}\p{N}]+/u', '', $w);
        $w = preg_replace('/(ий|ій|ый|ой|ая|ое|ого|ому|им|их|ої|ою|а|я|е|і)$/u', '', $w);

        return $w;
    }

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
