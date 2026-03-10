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
        if ($value === null) {
            return null;
        }
        $v = trim(mb_strtolower($value));
        if ($v === '') {
            return null;
        }

        $map = [
            'multicam' => [
                'multicam', 'мультикам', 'мультикаму', 'мультікам', 'multicamo', 'multi-cam',
                'mc', 'mcb', 'mctp', 'mtb', 'mtp',
                'multicam tropic', 'multicam black', 'multicam arid', 'multicam tropic', 'multicam black',
            ],
            'black' => ['black', 'чорний', 'чорна', 'чорне', 'черный', 'чёрный', 'чёрна'],
            'olive' => ['olive', 'олива', 'оливковий', 'оливковая', 'оливкове', 'оливковий'],
            'coyote' => ['coyote', 'койот', 'койоті', 'coyote brown', 'coyotebrown'],
            'sand' => ['sand', 'пісочний', 'песочный', 'піщаний', 'tan', 'desert'],
            'green' => ['green', 'зелений', 'зелена', 'зелене'],
            'pink' => ['pink', 'рожевий', 'рожева', 'рожеве', 'розовый', 'розовая', 'ніжно рожевий'],
            'blue' => ['blue', 'синій', 'синя', 'синє', 'блакитний', 'блакитна', 'navy'],
            'red' => ['red', 'червоний', 'червона', 'червоне', 'красный', 'красная'],
            'white' => ['white', 'білий', 'біла', 'біле', 'белый', 'белая'],
            'grey' => ['grey', 'gray', 'сірий', 'сіра', 'сіре', 'серый', 'серая'],
            'brown' => ['brown', 'коричневий', 'коричнева', 'коричневе'],
            'beige' => ['beige', 'бежевий', 'бежева', 'бежеве'],
            'orange' => ['orange', 'оранжевий', 'оранжева', 'оранжеве', 'помаранчевий'],
            'yellow' => ['yellow', 'жовтий', 'жовта', 'жовте'],
            'purple' => ['purple', 'фіолетовий', 'фіолетова', 'фіолетове', 'бордовий', 'бордова'],
            'khaki' => ['khaki', 'хакі'],
            'pixel' => ['pixel', 'піксель', 'пиксель'],
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
            'black' => ['black', 'Black', 'чорний', 'Чорний', 'чорна', 'Чорна'],
            'olive' => ['olive', 'Olive', 'олива', 'Олива', 'оливковий', 'Оливковий'],
            'coyote' => ['coyote', 'Coyote', 'койот', 'Койот'],
            'sand' => ['sand', 'Sand', 'пісочний', 'Пісочний', 'tan', 'Tan'],
            'green' => ['green', 'Green', 'зелений', 'Зелений', 'зелена', 'Зелена'],
            'pink' => ['pink', 'Pink', 'рожевий', 'Рожевий', 'рожева', 'Рожева'],
            'blue' => ['blue', 'Blue', 'синій', 'Синій', 'синя', 'Синя', 'блакитний', 'Блакитний'],
            'red' => ['red', 'Red', 'червоний', 'Червоний', 'червона', 'Червона'],
            'white' => ['white', 'White', 'білий', 'Білий', 'біла', 'Біла'],
            'grey' => ['grey', 'Grey', 'gray', 'Gray', 'сірий', 'Сірий', 'сіра', 'Сіра'],
            'brown' => ['brown', 'Brown', 'коричневий', 'Коричневий', 'коричнева', 'Коричнева'],
            'beige' => ['beige', 'Beige', 'бежевий', 'Бежевий', 'бежева', 'Бежева'],
            'orange' => ['orange', 'Orange', 'оранжевий', 'Оранжевий', 'помаранчевий', 'Помаранчевий'],
            'yellow' => ['yellow', 'Yellow', 'жовтий', 'Жовтий', 'жовта', 'Жовта'],
            'purple' => ['purple', 'Purple', 'фіолетовий', 'Фіолетовий', 'бордовий', 'Бордовий'],
            'khaki' => ['khaki', 'Khaki', 'хакі', 'Хакі'],
            'pixel' => ['pixel', 'Pixel', 'піксель', 'Піксель'],
        ];

        return $variants[$canonical] ?? [$canonical];
    }
}
