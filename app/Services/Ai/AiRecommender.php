<?php

namespace App\Services\Ai;

use Illuminate\Support\Collection;

class AiRecommender
{
    /**
     * @param string                 $query    текст запиту користувача
     * @param array|Collection       $products кандидати від ProductService
     * @param int                    $limit    скільки товарів повернути
     * @return array                 відсортований масив товарів (без _score)
     */
    public function recommend(string $query, $products, int $limit = 3): array
    {
        $productsCollection = $products instanceof Collection
            ? $products
            : collect($products);

        if ($productsCollection->isEmpty()) {
            return [];
        }

        $queryLower = mb_strtolower($query, 'UTF-8');
        $tokens     = $this->tokenize($queryLower);

        $filters = $this->extractFilters($tokens, $queryLower);
        $colorFilter = $filters['color'] ?? null;
        $sizeFilter  = $filters['size'] ?? null;
        $typeHints   = $filters['types'] ?? [];

        $scored = $productsCollection
            ->map(function ($product) use ($tokens, $colorFilter, $sizeFilter, $typeHints) {
                $p = $this->normalizeProduct($product);

                $titleUa = mb_strtolower($p['title_json']['ua'] ?? '', 'UTF-8');
                $titleRu = mb_strtolower($p['title_json']['ru'] ?? '', 'UTF-8');
                $title   = mb_strtolower($p['title'] ?? '', 'UTF-8');

                $categoryPath = mb_strtolower($p['category_path'] ?? '', 'UTF-8');
                $searchIndex  = mb_strtolower($p['search_index'] ?? '', 'UTF-8');
                $color        = mb_strtolower((string)($p['color'] ?? ''), 'UTF-8');

                $haystack = $title . ' ' . $titleUa . ' ' . $titleRu . ' ' . $categoryPath . ' ' . $searchIndex;

                $score = 0.0;

                // 1) Базовий текстовий матч по токенах
                foreach ($tokens as $i => $token) {
                    if ($token === '' || mb_strlen($token, 'UTF-8') < 2) {
                        continue;
                    }

                    if (str_contains($haystack, $token)) {
                        $score += 10;

                        // Перший змістовний токен — трохи важливіший
                        if ($i === 0) {
                            $score += 5;
                        }
                    }
                }

                // 2) Визначаємо тип товару
                $isHelmet          = $this->isHelmet($title, $titleUa, $titleRu, $categoryPath);
                $isHelmetAccessory = $this->isHelmetAccessory($title, $titleUa, $titleRu, $categoryPath);
                $isPlate           = $this->isPlate($title, $titleUa, $titleRu, $categoryPath);
                $isPlateCarrier    = $this->isPlateCarrier($title, $titleUa, $titleRu, $categoryPath);
                $isJacket          = $this->isJacket($title, $titleUa, $titleRu, $categoryPath);
                $isPatch           = $this->isPatch($title, $titleUa, $titleRu, $categoryPath);
                $isKnife           = $this->isKnife($title, $titleUa, $titleRu, $categoryPath);

                // 2.1) Шоломи / аксесуари до шоломів
                if (in_array('helmet', $typeHints, true)) {
                    if ($isHelmet) {
                        $score += 40;
                    }

                    if ($isHelmetAccessory) {
                        $score -= 25;
                    }
                }

                // 2.2) Плити / плитоноски / бронежилети
                if (in_array('plate', $typeHints, true)) {
                    if ($isPlate) {
                        $score += 40;
                    }

                    if ($isPlateCarrier) {
                        $score += 20;
                    }

                    // бронежилет — менш пріоритетний, ніж явні плити
                    if (str_contains($categoryPath, 'бронежилет')) {
                        $score += 5;
                    }
                }

                if (in_array('jacket', $typeHints, true)) {
                    if ($isJacket) {
                        $score += 30;
                    }
                }

                if (in_array('patch', $typeHints, true)) {
                    if ($isPatch) {
                        $score += 30;
                    } else {
                        // якщо явно просили патч — все інше не дуже релевантне
                        $score -= 20;
                    }
                }

                if (in_array('knife', $typeHints, true)) {
                    if ($isKnife) {
                        $score += 30;
                    } else {
                        $score -= 15;
                    }
                }

                // 3) Колір — якщо явно заданий у запиті
                if ($colorFilter !== null) {
                    if ($this->colorMatches($colorFilter, $color, $haystack)) {
                        $score += 25;
                    } else {
                        // Явний колір у запиті, але інший у товарі — сильний штраф
                        $score -= 40;
                    }
                } else {
                    // Мʼякий бонус, якщо колір з назви співпадає з якимось словом із запиту
                    if ($color !== '' && $this->colorMatchesAnyToken($color, $tokens)) {
                        $score += 5;
                    }
                }

                // 4) Розмір — якщо явно заданий (XL/ХЛ/L/M)
                if ($sizeFilter !== null) {
                    if ($this->sizeMatches($sizeFilter, $haystack)) {
                        $score += 20;
                    } else {
                        $score -= 10;
                    }
                }

                // 5) Популярність
                $ordersCount      = (int)($p['orders_count'] ?? 0);
                $addedToCartCount = (int)($p['added_to_cart_count'] ?? 0);

                $score += min($ordersCount, 50) * 0.5;
                $score += min($addedToCartCount, 50) * 0.3;

                // Мінімальний базовий скор, щоб не було суцільних відʼємних
                if ($score < -50) {
                    $score = -50;
                }

                $p['_score'] = $score;

                return $p;
            })
            ->sortByDesc('_score')
            ->values();

        // Повертаємо топ-товари без поля _score
        return $scored
            ->take($limit)
            ->map(function (array $p) {
                unset($p['_score']);

                return $p;
            })
            ->all();
    }

    /**
     * Нормалізує товар до масиву.
     */
    protected function normalizeProduct($product): array
    {
        if (is_array($product)) {
            return $product;
        }

        if ($product instanceof \JsonSerializable) {
            /** @var array $arr */
            $arr = $product->jsonSerialize();

            return $arr;
        }

        if (is_object($product) && method_exists($product, 'toArray')) {
            /** @var array $arr */
            $arr = $product->toArray();

            return $arr;
        }

        return (array) $product;
    }

    /**
     * Токенізація запиту.
     */
    protected function tokenize(string $queryLower): array
    {
        // Прибираємо знаки пунктуації
        $clean = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $queryLower) ?? '';
        $raw   = preg_split('/\s+/u', trim($clean)) ?: [];

        $stopWords = [
            'я', 'ми', 'ти', 'ви',
            'хочу', 'шукаю', 'потрібна', 'потрібен', 'потрібно',
            'будь', 'будьласка', 'будь-ласка', 'будь ласка',
            'для', 'на', 'у', 'в', 'та', 'і', 'або',
            'до', 'з', 'по', 'про',
            'куртку', 'куртка', 'куртки', // залишимо, але можемо вийняти, якщо заважатиме
        ];

        $tokens = [];
        foreach ($raw as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }

            if (in_array($token, $stopWords, true)) {
                continue;
            }

            $tokens[] = $token;
        }

        return $tokens;
    }

    /**
     * Витягуємо типи, колір, розмір з запиту.
     *
     * @param array  $tokens
     * @param string $queryLower
     * @return array{types: array, color: string|null, size: string|null}
     */
    protected function extractFilters(array $tokens, string $queryLower): array
    {
        $types  = [];
        $color  = null;
        $size   = null;

        // --- Типи товарів ---
        // Шолом
        if ($this->containsAny($tokens, ['шолом', 'шоломи', 'каска', 'каски', 'helmet'])) {
            $types[] = 'helmet';
        }

        // Плити
        if ($this->containsAny($tokens, ['плита', 'плити', 'бронеплита', 'бронеплити', 'sapi', 'plate'])) {
            $types[] = 'plate';
        }

        // Куртка
        if ($this->containsAny($tokens, ['куртка', 'куртку', 'куртки', 'пуховик', 'jacket'])) {
            $types[] = 'jacket';
        }

        // Патч
        if ($this->containsAny($tokens, ['патч', 'патчі', 'нашивка', 'нашивки'])) {
            $types[] = 'patch';
        }

        // Ніж
        if ($this->containsAny($tokens, ['ніж', 'ножі', 'knife', 'нож'])) {
            $types[] = 'knife';
        }

        $types = array_values(array_unique($types));

        // --- Колір ---
        $colorMap = [
            'black'    => ['чорн', 'black'],
            'olive'    => ['олив', 'olive'],
            'coyote'   => ['койот', 'coyote'],
            'green'    => ['зелен', 'green'],
            'multicam' => ['мультикам', 'multicam', 'mc'],
        ];

        foreach ($colorMap as $key => $variants) {
            foreach ($variants as $v) {
                if (str_contains($queryLower, $v)) {
                    $color = $key;
                    break 2;
                }
            }
        }

        // --- Розмір ---
        $sizeMap = [
            'XL' => ['xl', 'хл', 'x-large', 'x large', 'xл'],
            'L'  => [' l ', ' l,', ' l.', '(l)', 'розмір l', 'size l'],
            'M'  => [' m ', ' m,', ' m.', '(m)', 'розмір m', 'size m'],
        ];

        foreach ($sizeMap as $key => $variants) {
            foreach ($variants as $v) {
                if (str_contains(' ' . $queryLower . ' ', $v)) {
                    $size = $key;
                    break 2;
                }
            }
        }

        return [
            'types' => $types,
            'color' => $color,
            'size'  => $size,
        ];
    }

    protected function containsAny(array $tokens, array $needles): bool
    {
        foreach ($tokens as $t) {
            foreach ($needles as $n) {
                if (str_contains($t, $n)) {
                    return true;
                }
            }
        }

        return false;
    }

    // ---- Визначення типів товарів ----

    protected function isHelmet(string $title, string $titleUa, string $titleRu, string $categoryPath): bool
    {
        $haystack = $title . ' ' . $titleUa . ' ' . $titleRu . ' ' . $categoryPath;

        return str_contains($haystack, 'шолом')
            || str_contains($haystack, 'каска')
            || str_contains($haystack, 'helmet');
    }

    protected function isHelmetAccessory(string $title, string $titleUa, string $titleRu, string $categoryPath): bool
    {
        $haystack = $title . ' ' . $titleUa . ' ' . $titleRu . ' ' . $categoryPath;

        if (! $this->isHelmet($title, $titleUa, $titleRu, $categoryPath)
            && (str_contains($haystack, 'шолом') || str_contains($haystack, 'helmet'))) {
            // якщо згадується шолом, але не як основний товар
            if (str_contains($haystack, 'кріплення')
                || str_contains($haystack, 'adapter')
                || str_contains($haystack, 'адаптер')
                || str_contains($haystack, 'планка')
                || str_contains($haystack, 'picatinny')
                || str_contains($haystack, 'кавер')
                || str_contains($haystack, 'чохол')) {
                return true;
            }
        }

        return str_contains($categoryPath, 'аксесуари та комплектуючі')
            || str_contains($categoryPath, 'аксесуари')
            || str_contains($categoryPath, 'комплектуючі');
    }

    protected function isPlate(string $title, string $titleUa, string $titleRu, string $categoryPath): bool
    {
        $haystack = $title . ' ' . $titleUa . ' ' . $titleRu . ' ' . $categoryPath;

        return str_contains($haystack, 'бронеплита')
            || str_contains($haystack, 'бронеплити')
            || str_contains($haystack, 'плита')
            || str_contains($haystack, 'sapi');
    }

    protected function isPlateCarrier(string $title, string $titleUa, string $titleRu, string $categoryPath): bool
    {
        $haystack = $title . ' ' . $titleUa . ' ' . $titleRu . ' ' . $categoryPath;

        return str_contains($haystack, 'плитоноска')
            || str_contains($haystack, 'plate carrier')
            || str_contains($categoryPath, 'плитоноски');
    }

    protected function isJacket(string $title, string $titleUa, string $titleRu, string $categoryPath): bool
    {
        $haystack = $title . ' ' . $titleUa . ' ' . $titleRu . ' ' . $categoryPath;

        return str_contains($haystack, 'куртка')
            || str_contains($haystack, 'jacket')
            || str_contains($haystack, 'ecwcs')
            || str_contains($categoryPath, 'куртки');
    }

    protected function isPatch(string $title, string $titleUa, string $titleRu, string $categoryPath): bool
    {
        $haystack = $title . ' ' . $titleUa . ' ' . $titleRu . ' ' . $categoryPath;

        return str_contains($haystack, 'патч')
            || str_contains($haystack, 'нашивка')
            || str_contains($categoryPath, 'патч')
            || str_contains($categoryPath, 'нашивки');
    }

    protected function isKnife(string $title, string $titleUa, string $titleRu, string $categoryPath): bool
    {
        $haystack = $title . ' ' . $titleUa . ' ' . $titleRu . ' ' . $categoryPath;

        return str_contains($haystack, 'ніж')
            || str_contains($haystack, 'нож')
            || str_contains($haystack, 'knife')
            || str_contains($categoryPath, 'ножі');
    }

    // ---- Колір ----

    /**
     * @param string      $colorFilter  one of: black, olive, coyote, green, multicam
     * @param string|null $productColor product->color
     * @param string      $haystack     title + category + search_index
     */
    protected function colorMatches(string $colorFilter, ?string $productColor, string $haystack): bool
    {
        $productColor = $productColor ?? '';

        $map = [
            'black'    => ['чорн', 'black'],
            'olive'    => ['олив', 'olive'],
            'coyote'   => ['койот', 'coyote'],
            'green'    => ['зелен', 'green'],
            'multicam' => ['мультикам', 'multicam', 'mc'],
        ];

        $variants = $map[$colorFilter] ?? [];

        foreach ($variants as $v) {
            if (str_contains($productColor, $v) || str_contains($haystack, $v)) {
                return true;
            }
        }

        return false;
    }

    protected function colorMatchesAnyToken(string $productColor, array $tokens): bool
    {
        $productColor = mb_strtolower($productColor, 'UTF-8');

        foreach ($tokens as $t) {
            if ($t === '') {
                continue;
            }

            if (str_contains($productColor, $t) || str_contains($t, $productColor)) {
                return true;
            }
        }

        return false;
    }

    // ---- Розмір ----

    /**
     * @param string $sizeFilter  XL/L/M
     * @param string $haystack    title + category + search_index
     */
    protected function sizeMatches(string $sizeFilter, string $haystack): bool
    {
        $haystack = ' ' . mb_strtolower($haystack, 'UTF-8') . ' ';

        $sizeFilter = strtoupper($sizeFilter);

        $map = [
            'XL' => [' xl', 'xl ', 'x-large', 'x large', 'хл', ' xл', 'xл '],
            'L'  => [' l ', ' l,', ' l.', '(l)', 'розмір l', 'size l'],
            'M'  => [' m ', ' m,', ' m.', '(m)', 'розмір m', 'size m'],
        ];

        $variants = $map[$sizeFilter] ?? [];

        foreach ($variants as $v) {
            if (str_contains($haystack, $v)) {
                return true;
            }
        }

        return false;
    }
}
