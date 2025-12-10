<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Str;

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
     * 2) Fuzzy пошук
     * 3) Пошук по декількох умовах
     * 4) fallback → Horoshop API
     */
    public function searchByText(string $text, int $limit = 20): array
    {
        // 1 — AI нормалізація
        $normalized = $this->router->normalizeSearchQuery($text);

        // 2 — генеруємо часткові токени для fuzzy-пошуку
        $tokens = $this->generateFuzzyTokens($normalized);

        $query = Product::query();

        foreach ($tokens as $token) {
            $query->orWhere('search_index', 'LIKE', "%$token%");
        }

        $results = $query->limit($limit)->get();

        // Якщо знайшли — повертаємо локальні дані
        if ($results->count() > 0) {
            return $results->toArray();
        }

        // 4 — fallback до Horoshop API
        return app(HoroshopService::class)->searchProducts($normalized);
    }

    /**
     * Генерує fuzzy-токени (спрощено).
     */
    protected function generateFuzzyTokens(string $text): array
    {
        $text = Str::lower($text);

        $parts = explode(' ', $text);

        $tokens = [];

        foreach ($parts as $p) {
            if (strlen($p) < 3) continue;

            $tokens[] = $p; // прямий токен

            // fuzzy варіанти
            $tokens[] = substr($p, 0, 4);
            $tokens[] = substr($p, 0, 3);

            // ручні заміни (популярні помилки)
            $tokens[] = str_replace('є', 'е', $p);
            $tokens[] = str_replace('и', 'і', $p);
            $tokens[] = str_replace('о', 'а', $p);
        }

        return array_unique($tokens);
    }
}
