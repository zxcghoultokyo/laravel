<?php

namespace App\Services\Horoshop;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class ProductService
{
    public function __construct(
        protected HoroshopClient $client,
    ) {}

    /**
     * Простий пошук товарів по тексту.
     *
     * @param  string  $query  – що написав юзер («плитоноска», «рюкзак 40л» тощо)
     * @param  int     $limit  – скільки товарів максимум віддати
     * @return array
     */
    public function searchByText(string $query, int $limit = 10): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        // 1) Тягнемо шматок каталогу з Хорошопу
        //    (до 200 товарів, тільки базові поля, щоб не вбивати API)
        $payload = [
            'limit'          => 200,
            'includedParams' => ['title', 'description', 'price', 'price_old', 'parent', 'slug', 'link', 'images'],
        ];

        $data = $this->client->postJson('catalog/export', $payload);

        $products = $data['response']['products'] ?? [];
        if (empty($products)) {
            return [];
        }

        // 2) Фільтруємо по входженню тексту в назву/опис
        $q = mb_strtolower($query, 'UTF-8');
        $matched = [];

        foreach ($products as $p) {
            $titleUa = $p['title']['ua'] ?? '';
            $titleRu = $p['title']['ru'] ?? '';
            $descUa  = $p['description']['ua'] ?? '';
            $descRu  = $p['description']['ru'] ?? '';

            $haystack = mb_strtolower(
                implode(' ', [$titleUa, $titleRu, $descUa, $descRu]),
                'UTF-8'
            );

            if ($haystack === '') {
                continue;
            }

            if (mb_stripos($haystack, $q, 0, 'UTF-8') === false) {
                continue;
            }

            $matched[] = [
                'article'   => $p['article']         ?? null,
                'title'     => $titleUa ?: $titleRu,
                'title_raw' => $p['title']           ?? null,
                'price'     => $p['price']           ?? null,
                'price_old' => $p['price_old']       ?? null,
                'parent'    => $p['parent']['value'] ?? null,
                'slug'      => $p['slug']            ?? null,
                'link'      => $p['link']            ?? null,
                'images'    => $p['images']          ?? [],
            ];
        }

        // 3) Просто відрізаємо до ліміту
        return array_slice($matched, 0, $limit);
    }
}
