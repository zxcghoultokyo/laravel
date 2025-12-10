<?php

namespace App\Services\Ai;

/**
 * Простий "розумний" рекоммендер товарів.
 *
 * Задача на зараз:
 * - прийняти текст запиту користувача
 * - прийняти масив товарів з Horoshop
 * - повернути ті ж товари, але відсортовані за "релевантністю"
 *
 * Логіка дуже базова, але вже дає щось адекватне:
 * - в пріоритеті товари "в наявності"
 * - плюс за знижку
 * - плюс за популярність
 * - плюс, якщо текст запиту зустрічається в назві/описі
 */
class AiRecommender
{
    /**
     * @param string $query    Текст запиту користувача
     * @param array  $products Масив товарів з Horoshop (catalog/export або search)
     * @param int    $limit    Максимальна кількість товарів у відповіді
     * @return array           Ті ж товари, але відсортовані й обрізані до $limit
     */
    public function recommend(string $query, array $products, int $limit = 5): array
    {
        if (empty($products)) {
            return [];
        }

        $queryLower = mb_strtolower($query);

        $scored = [];

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
                    if (mb_strpos($presenceText, 'в наявності') !== false
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
