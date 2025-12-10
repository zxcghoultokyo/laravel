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

    public function searchByText(string $text, int $limit = 20): array
    {
        $normalized = $this->router->normalizeSearchQuery($text);
        $tokens = $this->generateFuzzyTokens($normalized);

        $query = Product::query();

        foreach ($tokens as $token) {
            $query->orWhere('search_index', 'LIKE', '%' . $token . '%');
        }

        $results = $query->limit($limit)->get();

        if ($results->count() > 0) {
            return $results->toArray();
        }

        return app(HoroshopService::class)->searchProducts($normalized);
    }

    protected function generateFuzzyTokens(string $text): array
    {
        $text = Str::lower($text);
        $parts = explode(' ', $text);

        $tokens = [];

        foreach ($parts as $p) {
            if (strlen($p) < 3) continue;

            $tokens[] = $p;
            $tokens[] = substr($p, 0, 4);
            $tokens[] = substr($p, 0, 3);
            $tokens[] = str_replace('є', 'е', $p);
            $tokens[] = str_replace('и', 'і', $p);
            $tokens[] = str_replace('о', 'а', $p);
        }

        return array_unique($tokens);
    }
}
