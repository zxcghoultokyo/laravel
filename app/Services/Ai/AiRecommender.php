<?php

namespace App\Services\Ai;

/**
 * Простий "розумний" рекоммендер товарів.
 *
 * Підтримує ДВА варіанти виклику:
 *
 * 1) recommend($query, $products, $limit)
 *    - $query  (string) — текст запиту користувача
 *    - $products (array) — масив товарів з Horoshop
 *
 * 2) recommend($products)
 *    - якщо переданий тільки масив товарів — просто відсортовуємо їх за популярністю/наявністю/знижкою
 */
class AiRecommender
{
    /**
     * @param string|array $queryOrProducts
     *   - string: текст запиту користувача
     *   - array:  масив товарів, якщо викликають recommend($products)
     * @param array|null $products
     *   - масив товарів, коли викликають recommend($query, $products)
     * @param int $limit
     * @return array
     */
    public function recommend(string|array $queryOrProducts, ?array $products = null, int $limit = 5): array
    {
        // Визначаємо, як нас викликали

        if ($products === null) {
            // Виклик виду: recommend($products)
            $query    = '';
            $products = is_array($queryOrProducts) ? $queryOrProducts : [];
        } else {
            // Виклик виду: recommend($query, $products)
            $query = is_string($queryOrProducts) ? $queryOrProducts : '';
        }

        if (empty($products)) {
            return [];
        }

        $queryLower = mb_strtolower($query);
        $scored     = [];

        foreach ($products as $product) {
            $score = 0;

            // 1) Популярність
            if (isset($product['popularity'])) {
                $score += (int) $product['popularity'];
            }

            // 2) Знижка
            if (isset($product['discount']) && (int) $product['discount'] > 0) {
                $score += 10;
            }

            // 3) Наявність (presence.value.ua / presence.value.ru)
            $presenceBoost = 0;
            if (isset($product['presence']['value']) && is_array($product['presence']['value'])) {
                $presenceUa = isset($product['presence']['value']['ua'])
                    ? mb_strtolower($product['presence']['value']['ua'])
                    : null;
                $presenceRu = isset($product['presence']['value']['ru'])
                    ? mb_strtolower($product['presence']['value']['ru'])
                    : null;

                $presenceText = $presenceUa ?: $presenceRu;

                if ($presenceText !== null) {
                    if (
                        mb_strpos($presenceText, 'в наявності') !== false
                        || mb_strpos($presenceText, 'у наявності') !== false
                        || mb_strpos($presenceText, 'в наличии') !== false
                    ) {
                        $presenceBoost = 20;
                    }
                }
            }
            $score += $presenceBoost;

            // 4) Матч по тексту запиту в назві / описі
            $textBlob = '';

            // Назва
            if (isset($product['title'])) {
                if (is_array($product['title'])) {
                    $textBlob .= ' ' . ($product['title']['ua'] ?? '');
                    $textBlob .= ' ' . ($product['title']['ru'] ?? '');
                } else {
                    $textBlob .= ' ' . (string) $product['title'];
                }
            }

            // Опис
            if (isset($product['description'])) {
                if (is_array($product['description'])) {
                    $textBlob .= ' ' . ($product['description']['ua'] ?? '');
                    $textBlob .= ' ' . ($product['description']['ru'] ?? '');
                } else {
                    $textBlob .= ' ' . (string) $product['description'];
                }
            }

            $textLower = mb_strtolower($textBlob);

            if ($queryLower !== '' && $textLower !== '') {
                if (mb_strpos($textLower, $queryLower) !== false) {
                    // Якщо весь запит є підрядком – гарний матч
                    $score += 30;
                } else {
                    // Проста перевірка по словам
                    $words = preg_split('/\s+/u', $queryLower, -1, PREG_SPLIT_NO_EMPTY);
                    $matchedWords = 0;

                    foreach ($words as $word) {
                        if (mb_strlen($word) < 3) {
                            continue; // дрібні слова типу "і", "в", "на" пропускаємо
                        }
                        if (mb_strpos($textLower, $word) !== false) {
                            $matchedWords++;
                        }
                    }

                    if ($matchedWords > 0) {
                        // +5 балів за кожне співпадіння, максимум +25
                        $score += min($matchedWords * 5, 25);
                    }
                }
            }

            $scored[] = [
                'score'   => $score,
                'product' => $product,
            ];
        }

        // Сортуємо за score по спаданню
        usort($scored, static function (array $a, array $b) {
            return $b['score'] <=> $a['score'];
        });

        // Обрізаємо до $limit і повертаємо тільки самі товари
        $result = array_slice($scored, 0, $limit);

        return array_map(static function (array $row) {
            return $row['product'];
        }, $result);
    }
}
