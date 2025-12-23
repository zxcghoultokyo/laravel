<?php

namespace App\Support;

class ColorNormalizer
{
    /**
     * Map various color/camo synonyms to canonical codes used for filtering.
     * Returns canonical like: multicam, black, olive, coyote, sand, green.
     */
    public static function toNorm(?string $value): ?string
    {
        if ($value === null) return null;
        $v = trim(mb_strtolower($value));
        if ($v === '') return null;

        $map = [
            'multicam' => ['multicam', 'мультикам', 'мультикаму', 'мультікам', 'multicamo', 'multi-cam'],
            'black'    => ['black', 'чорний', 'чорна', 'чорне', 'черный', 'чёрный', 'чёрна'],
            'olive'    => ['olive', 'олива', 'оливковий', 'оливковая', 'оливкове', 'оливковий'],
            'coyote'   => ['coyote', 'койот', 'койоті', 'coyote brown', 'coyotebrown'],
            'sand'     => ['sand', 'пісочний', 'песочный', 'піщаний', 'tan', 'desert'],
            'green'    => ['green', 'зелений', 'зелена', 'зелене'],
        ];

        foreach ($map as $canon => $syns) {
            foreach ($syns as $s) {
                if ($v === $s) {
                    return $canon;
                }
            }
        }

        // Heuristic: contains token
        foreach ($map as $canon => $syns) {
            foreach ($syns as $s) {
                if (str_contains($v, $s)) {
                    return $canon;
                }
            }
        }

        return null;
    }

    /**
     * Return common literal variants for a canonical color to match legacy `color` values.
     */
    public static function literalVariants(string $canonical): array
    {
        $variants = [
            'multicam' => ['multicam', 'Multicam', 'мультикам', 'Мультикам'],
            'black'    => ['black', 'Black', 'чорний', 'Чорний', 'чорна', 'Чорна'],
            'olive'    => ['olive', 'Olive', 'олива', 'Олива', 'оливковий', 'Оливковий'],
            'coyote'   => ['coyote', 'Coyote', 'койот', 'Койот'],
            'sand'     => ['sand', 'Sand', 'пісочний', 'Пісочний', 'tan', 'Tan'],
            'green'    => ['green', 'Green', 'зелений', 'Зелений', 'зелена', 'Зелена'],
        ];
        return $variants[$canonical] ?? [$canonical];
    }
}
