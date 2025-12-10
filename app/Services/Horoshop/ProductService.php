<?php

namespace App\Services\Horoshop;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Builder;

class ProductService
{
    protected HoroshopClient $client;

    public function __construct(HoroshopClient $client)
    {
        $this->client = $client;
    }

    /**
     * Головний метод пошуку товарів по текстовому запиту.
     *
     * Використовується ChatController'ом та DebugProductsController'ом.
     *
     * @param  string      $rawQuery
     * @param  int|null    $categoryId   (поки не використовується, залишено для майбутнього)
     * @param  string      $language
     * @return array       Масив нормалізованих товарів для API
     */
    public function searchByText(string $rawQuery, ?int $categoryId = null, string $language = 'uk'): array
    {
        Log::info('ProductService::searchByText', [
            'raw_query'   => $rawQuery,
            'category_id' => $categoryId,
            'language'    => $language,
        ]);

        $normalized = mb_strtolower(trim($rawQuery));
        if ($normalized === '') {
            return [];
        }

        // 1) Робимо базову нормалізацію запиту без доменних синонімів.
        $expandedQuery = $normalized;

        // 2) Дістаємо цінові фільтри з тексту ("до 5 тис", "від 3 до 7 тис" тощо)
        $priceFilters = $this->extractPriceFiltersFromQuery($normalized);

        // 3) Шукаймо кандидатів у локальній БД з урахуванням:
        //    - display_in_showcase = 1
        //    - in_stock = 1
        //    - min_price / max_price (якщо витягнулись з запиту)
        $candidates = $this->findCandidates($expandedQuery, $categoryId, $priceFilters);

        if ($candidates->isEmpty()) {
            Log::info('ProductService::searchByText no candidates found', [
                'expanded_query' => $expandedQuery,
                'price_filters'  => $priceFilters,
            ]);

            return [];
        }

        // 4) Рахуємо скор для кожного товару
        $scored = $this->scoreProducts($candidates, $expandedQuery);

        if ($scored->isEmpty()) {
            Log::info('ProductService::searchByText all candidates filtered out by score');
            return [];
        }

        // 5) Фільтруємо за відносним порогом релевантності
        $maxScore = $scored->max('score') ?? 0.0;

        if ($maxScore < 1.0) {
            Log::info('ProductService::searchByText max score too low', [
                'max_score'      => $maxScore,
