<?php

namespace App\Services\Horoshop;

use App\Models\Product;
use Illuminate\Support\Str;
use App\Services\Ai\AiRouter;
use App\Services\Horoshop\HoroshopService;

class ProductService
{
    protected AiRouter $router;

    public function __construct(AiRouter $router)
    {
        $this->router = $router;
    }

    /**
     * 🔥 Розумний пошук:
     * 1) AI нормалізація
     * 2) Fuzzy-пошук по search_index
     * 3) Fallback до Horoshop API, якщо в БД нічого не знайшли
     */
    public function searchByText(string $text, int $limit = 20): array
    {
        // 1 — AI нормалізація фрази
        $normalized = $this->router->normalizeSearchQuery($text);

        // 2 — генеруємо токени для fuzzy-пошуку
        $tokens = $this->generateFuzzyTokens($normalized);

        $query = Product::query();

        foreach ($tokens as $token) {
            $query->orWhere('search_index', 'LIKE', '%' . $token . '%');
        }

        $results = $query->limit($limit)->get();

        // Якщо щось знайшли в локальній БД — повертаємо
        if ($results->count() > 0) {
            return $results->toArray();
        }

        // Fallback — напряму в Horoshop
        return app(HoroshopService::class)->searchProducts($normalized);
    }

    /**
     * Примітивний fuzzy-генератор токенів для LIKE-пошуку
     */
    protected function generateFuzzyTokens(string $text): array
    {
        $text = Str::lower($text);
        $parts = explode(' ', $text);

        $tokens = [];

        foreach ($parts as $p) {
            if (strlen($p) < 3) {
                continue;
            }

            // базовий токен
            $tokens[] = $p;

            // укорочені форми
            $tokens[] = substr($p, 0, 4);
            $tokens[] = substr($p, 0, 3);

            // типові укр. помилки / варіації
            $tokens[] = str_replace('є', 'е', $p);
            $tokens[] = str_replace('и', 'і', $p);
            $tokens[] = str_replace('о', 'а', $p);
        }

        return array_unique($tokens);
    }
}
