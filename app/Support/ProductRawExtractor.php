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
        // Типові варіанти ключів (Horoshop може відрізнятися по проєктах)
        $candidates = [
            "description.$lang",
            "description_{$lang}",
            "desc.$lang",
            "text.$lang",
            'description.ua',
            'description.ru',
            'description',
            'desc',
            'text',
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
        // Найчастіші структури:
        // 1) properties: [{name:{ua:..}, value:{ua:..}}]
        // 2) characteristics: [{title:{ua:..}, values:[{title:{ua:..}}]}]
        // 3) params: { "Матеріал": "..." } — інколи

        $out = [];

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

        $chars = Arr::get($raw, 'characteristics');
        if (is_array($chars)) {
            foreach ($chars as $c) {
                $name = self::pickLang($c['title'] ?? null, $lang) ?: (is_string($c['title'] ?? null) ? $c['title'] : null);
                $vals = $c['values'] ?? null;
                if (! $name || ! is_array($vals)) {
                    continue;
                }

                $vTexts = [];
                foreach ($vals as $v) {
                    $vt = self::pickLang($v['title'] ?? null, $lang) ?: (is_string($v['title'] ?? null) ? $v['title'] : null);
                    if ($vt) {
                        $vTexts[] = trim($vt);
                    }
                }

                if ($vTexts) {
                    $out[trim($name)] = implode(', ', array_unique($vTexts));
                }
            }
        }

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
