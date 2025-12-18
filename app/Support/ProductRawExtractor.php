<?php

namespace App\Support;

use Illuminate\Support\Arr;

class ProductRawExtractor
{
    /**
     * Спроба витягнути опис з Horoshop raw.
     * Повертає короткий plain text (без HTML).
     */
    public static function description(array $raw, string $lang = 'ua', array $parentRaw = []): string
    {
        $val = self::descriptionHtml($raw, $lang, $parentRaw);

        // чистимо html
        $val = strip_tags($val);
        $val = preg_replace('/\s+/u', ' ', $val) ?: '';

        return trim($val);
    }

    /**
     * Те ж саме, але зберігаємо HTML (для евристичного парсингу характеристик).
     */
    private static function descriptionHtml(array $raw, string $lang = 'ua', array $parentRaw = []): string
    {
        // Horoshop формат: description.ua / description.ru
        $candidates = [
            "description.{$lang}",
            'description.ua',
            'description.ru',
            'description',
            // Деякі проєкти кладуть опис у characteristics.opisanie
            "characteristics.opisanie.{$lang}",
            'characteristics.opisanie.ua',
            'characteristics.opisanie.ru',
            'characteristics.opisanie',
            "short_description.{$lang}",
            'short_description.ua',
            'short_description.ru',
            'short_description',
        ];

        $val = '';
        foreach ($candidates as $path) {
            $tmp = Arr::get($raw, $path);
            if (is_string($tmp) && trim($tmp) !== '') {
                $val = $tmp;
                break;
            }
        }

        if ($val === '' && $parentRaw) {
            return self::descriptionHtml($parentRaw, $lang, []);
        }

        return (string) $val;
    }

    /**
     * Витягнути характеристики у вигляді ["Матеріал" => "Cordura", ...]
     * і повернути асоціативний масив.
     */
    public static function attributes(array $raw, string $lang = 'ua', array $parentRaw = []): array
    {
        // Horoshop формат: characteristics як об'єкт {"material": {...}, "weight": {...}}
        $out = [];

        // 1) characteristics (основний формат)
        $chars = Arr::get($raw, 'characteristics');
        if (is_array($chars)) {
            foreach ($chars as $key => $charData) {
                if ($key === 'opisanie') {
                    // опис обробляємо окремо
                    continue;
                }
                $name = ucfirst(str_replace('_', ' ', (string) $key));

                if (is_array($charData)) {
                    $value = self::pickLang($charData['value'] ?? null, $lang)
                        ?: self::pickLang($charData, $lang)
                        ?: ($charData['value'] ?? null);

                    if (is_string($value) && trim($value) !== '') {
                        $out[trim($name)] = trim($value);
                    }
                } elseif (is_string($charData) || is_numeric($charData)) {
                    $out[trim($name)] = trim((string) $charData);
                }
            }
        }

        // 2) select (варіативні атрибути)
        $select = Arr::get($raw, 'select');
        if (is_array($select)) {
            foreach ($select as $key => $val) {
                $name = ucfirst(str_replace('_', ' ', (string) $key));
                $v = self::pickLang($val, $lang) ?: self::asText($val);
                if (is_string($v) && trim($v) !== '') {
                    $out[trim($name)] = trim($v);
                }
            }
        }

        // 3) params (простий map)
        $params = Arr::get($raw, 'params');
        if (is_array($params)) {
            foreach ($params as $k => $v) {
                if (! is_string($k)) {
                    continue;
                }
                $vText = self::asText($v);
                if ($vText !== '') {
                    $out[trim($k)] = trim($vText);
                }
            }
        }

        // 4) properties (альтернативний формат)
        $props = Arr::get($raw, 'properties');
        if (is_array($props)) {
            foreach ($props as $p) {
                $name = self::pickLang($p['name'] ?? null, $lang) ?: (is_string($p['name'] ?? null) ? $p['name'] : null);
                $value = self::pickLang($p['value'] ?? null, $lang) ?: (is_string($p['value'] ?? null) ? $p['value'] : null);

                if ($name && $value) {
                    $out[trim($name)] = trim(self::asText($value));
                }
            }
        }

        // 5) Якщо нічого не зібрали — спробувати з батьківського
        if (! $out && $parentRaw) {
            $out = self::attributes($parentRaw, $lang, []);
        }

        // 6) Якщо все ще порожньо — спробувати евристично витягти з опису (HTML/текст)
        if (! $out) {
            $descHtml = self::descriptionHtml($raw, $lang, $parentRaw);
            $heur = self::parseAttributesFromDescription($descHtml);
            if ($heur) {
                $out = $heur;
            }
        }

        // Пост-обробка: фільтрація шуму та обрізання довгих значень
        foreach ($out as $k => $v) {
            $k2 = mb_strtolower(trim($k));
            if (mb_strlen($k2) < 2) {
                unset($out[$k]);
                continue;
            }
            if (mb_strlen($v) > 300) {
                $out[$k] = mb_substr($v, 0, 300);
            }
        }

        return $out;
    }

    public static function attributesText(array $raw, string $lang = 'ua', array $parentRaw = []): string
    {
        $attrs = self::attributes($raw, $lang, $parentRaw);
        if (! $attrs) {
            return '';
        }

        $pairs = [];
        foreach ($attrs as $k => $v) {
            $pairs[] = "{$k}: {$v}";
        }

        return implode(' | ', $pairs);
    }

    private static function pickLang($val, string $lang): ?string
    {
        if (is_array($val)) {
            $x = $val[$lang] ?? $val['ua'] ?? $val['ru'] ?? null;
            return is_string($x) ? trim($x) : null;
        }

        return null;
    }

    private static function asText($v): string
    {
        if (is_string($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (string) $v;
        }
        if (is_array($v)) {
            return trim(json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return '';
    }

    /**
     * Евристичний парсер key:value з опису. Працює по простим правилам:
     * - замінює <br>, <li>, </p> на переноси
     * - шукає рядки з двокрапкою
     * - фільтрує занадто довгі/шумні ключі та значення
     */
    private static function parseAttributesFromDescription(string $html): array
    {
        $text = $html;
        if ($text === '') {
            return [];
        }

        // Нормалізуємо розмітку до рядків
        $replacements = [
            '/<\s*br\s*\/?\s*>/iu' => "\n",
            '/<\s*li\b[^>]*>/iu' => "\n- ",
            '/<\/(p|div|li|tr)\s*>/iu' => "\n",
            '/<\s*td\b[^>]*>/iu' => ' ',
            '/<\s*th\b[^>]*>/iu' => ' ',
        ];
        foreach ($replacements as $rx => $rep) {
            $text = preg_replace($rx, $rep, $text) ?? $text;
        }
        $text = strip_tags($text);

        // Уніфікуємо пробіли та переносимо за роздільниками
        $text = preg_replace('/[\t\r]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s*;\s*/u', ";\n", $text) ?? $text;
        $text = preg_replace('/\s*\|\s*/u', " | ", $text) ?? $text;
        $text = preg_replace('/\s*\n\s*/u', "\n", $text) ?? $text;

        $lines = array_filter(array_map('trim', explode("\n", $text)));
        $out = [];

        $maxPairs = 12;
        foreach ($lines as $line) {
            // Вимагаємо наявність двокрапки як роздільника
            if (mb_strpos($line, ':') === false) {
                continue;
            }
            [$k, $v] = array_map('trim', explode(':', $line, 2));
            if ($k === '' || $v === '') {
                continue;
            }

            // Фільтри якості ключа
            if (mb_strlen($k) < 2 || mb_strlen($k) > 40) {
                continue;
            }
            // Не брати типові службові слова
            $kl = mb_strtolower($k);
            if (preg_match('/opis|опис/iu', $kl)) {
                continue;
            }
            // Не брати рядки де ключ схожий на ціле речення (більше 7 слів)
            if (count(preg_split('/\s+/u', $k)) > 7) {
                continue;
            }

            // Фільтри якості значення
            if (mb_strlen($v) > 120) {
                $v = mb_substr($v, 0, 120);
            }
            if ($v === '' || $v === '-') {
                continue;
            }

            // Уніфікуємо пробіли в значенні
            $v = preg_replace('/\s+/u', ' ', $v) ?: $v;

            $out[$k] = trim($v);
            if (count($out) >= $maxPairs) {
                break;
            }
        }

        // Якщо зібрали менш ніж 2 — вважаємо шумом
        if (count($out) < 2) {
            return [];
        }

        return $out;
    }
}
