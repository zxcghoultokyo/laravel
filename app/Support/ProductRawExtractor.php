<?php

namespace App\Support;

use Illuminate\Support\Arr;

class ProductRawExtractor
{
    /**
     * Спроба витягнути опис з Horoshop raw.
     * Повертає короткий plain text (без HTML).
     */
    public static function description(array $raw, string $lang = 'ua'): string
    {
        // Horoshop формат: description.ua / description.ru
        $candidates = [
            "description.{$lang}",
            'description.ua',
            'description.ru',
            'description',
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

        // чистимо html
        $val = strip_tags($val);
        $val = preg_replace('/\s+/u', ' ', $val) ?: '';

        return trim($val);
    }

    /**
     * Витягнути характеристики у вигляді ["Матеріал" => "Cordura", ...]
     * і повернути:
     *  - assoc map
     *  - flatten string для індексації/LLM
     */
    public static function attributes(array $raw, string $lang = 'ua'): array
    {
        // Horoshop формат: characteristics як об'єкт {"material": {...}, "weight": {...}}
        $out = [];

        // 1. Horoshop characteristics (основний формат)
        $chars = Arr::get($raw, 'characteristics');
        if (is_array($chars)) {
            foreach ($chars as $key => $charData) {
                // Може бути: {"id": 0, "value": "текст"} або {"ru": "...", "ua": "..."}
                $name = ucfirst(str_replace('_', ' ', $key)); // material → Material

                if (is_array($charData)) {
                    // Спробувати витягнути value
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

        // 2. Fallback: properties (якщо є інший формат)
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

        // обрізати шум/надто довгі значення
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

    public static function attributesText(array $raw, string $lang = 'ua'): string
    {
        $attrs = self::attributes($raw, $lang);
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
}
