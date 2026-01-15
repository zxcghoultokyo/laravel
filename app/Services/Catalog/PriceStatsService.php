<?php

namespace App\Services\Catalog;

use App\Models\Product;
use Illuminate\Support\Facades\Cache;

/**
 * Price Statistics Service
 * 
 * Розраховує цінові пороги для магазину динамічно.
 * "Бюджетний" = нижче 25-го перцентиля
 * "Середній" = між 25-м і 75-м перцентилем
 * "Преміум" = вище 75-го перцентиля
 */
class PriceStatsService
{
    private const CACHE_KEY = 'catalog_price_stats';
    private const CACHE_TTL = 3600; // 1 година
    
    /**
     * Отримати статистику цін з кешу.
     */
    public function getStats(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return $this->calculateStats();
        });
    }
    
    /**
     * Розрахувати статистику цін.
     */
    public function calculateStats(): array
    {
        $prices = Product::where('in_stock', true)
            ->where('price', '>', 0)
            ->pluck('price')
            ->map(fn($p) => (float) $p)
            ->sort()
            ->values();
        
        $count = $prices->count();
        
        if ($count === 0) {
            return $this->getDefaultStats();
        }
        
        // Перцентилі
        $p10 = $prices->get((int) ($count * 0.10)) ?? $prices->first();
        $p25 = $prices->get((int) ($count * 0.25)) ?? $prices->first();
        $p50 = $prices->get((int) ($count * 0.50)) ?? $prices->avg();
        $p75 = $prices->get((int) ($count * 0.75)) ?? $prices->last();
        $p90 = $prices->get((int) ($count * 0.90)) ?? $prices->last();
        
        return [
            'count' => $count,
            'min' => (float) $prices->min(),
            'max' => (float) $prices->max(),
            'avg' => round($prices->avg(), 0),
            'median' => (float) $p50,
            
            // Пороги для категорій
            'budget_max' => (float) $p25,        // "бюджетний" = до 25%
            'mid_min' => (float) $p25,           // "середній" = 25-75%
            'mid_max' => (float) $p75,
            'premium_min' => (float) $p75,       // "преміум" = від 75%
            
            // Додаткові пороги
            'very_cheap' => (float) $p10,        // "дуже дешево" = до 10%
            'expensive' => (float) $p90,         // "дорого" = від 90%
            
            'updated_at' => now()->toDateTimeString(),
        ];
    }
    
    /**
     * Дефолтні значення якщо немає товарів.
     */
    private function getDefaultStats(): array
    {
        return [
            'count' => 0,
            'min' => 0,
            'max' => 0,
            'avg' => 0,
            'median' => 0,
            'budget_max' => 500,
            'mid_min' => 500,
            'mid_max' => 3000,
            'premium_min' => 3000,
            'very_cheap' => 200,
            'expensive' => 10000,
            'updated_at' => now()->toDateTimeString(),
        ];
    }
    
    /**
     * Отримати текстовий опис для промпту.
     */
    public function getPromptContext(): string
    {
        $stats = $this->getStats();
        
        return sprintf(
            "ЦІНОВІ ПОРОГИ МАГАЗИНУ (КРИТИЧНО!):\n" .
            "- Бюджетний/недорогий: до %d грн\n" .
            "- Середній ціновий сегмент: %d-%d грн\n" .
            "- Преміум/дорогий: від %d грн\n" .
            "- Середня ціна товару: %d грн\n\n" .
            "ОБОВ'ЯЗКОВО ФІЛЬТРУЙ ЦІНУ:\n" .
            "- 'недорого', 'бюджетний', 'дешевий', 'економ' → ОБОВ'ЯЗКОВО передавай price_max=%d в search_products!\n" .
            "- 'недороге для подарунку' → price_max=%d\n" .
            "- 'преміум', 'найкраще', 'топовий' → price_min=%d\n" .
            "- Без цінового фільтру покажуться ДОРОГІ товари — це ПОГАНО для бюджетного запиту!",
            (int) $stats['budget_max'],
            (int) $stats['mid_min'],
            (int) $stats['mid_max'],
            (int) $stats['premium_min'],
            (int) $stats['avg'],
            (int) $stats['budget_max'],
            (int) $stats['budget_max'],
            (int) $stats['premium_min']
        );
    }
    
    /**
     * Визначити ціновий сегмент товару.
     */
    public function getPriceSegment(float $price): string
    {
        $stats = $this->getStats();
        
        if ($price <= $stats['very_cheap']) {
            return 'very_cheap';
        } elseif ($price <= $stats['budget_max']) {
            return 'budget';
        } elseif ($price <= $stats['mid_max']) {
            return 'mid';
        } elseif ($price >= $stats['expensive']) {
            return 'very_expensive';
        } else {
            return 'premium';
        }
    }
    
    /**
     * Очистити кеш (викликати після оновлення товарів).
     */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
