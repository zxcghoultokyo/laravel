<?php

namespace App\Enums;

/**
 * Chat intent types for agent classification.
 */
enum Intent: string
{
    case ProductSearch = 'product_search';
    case ProductComparison = 'product_comparison';
    case OrderStatus = 'order_status';
    case Faq = 'faq';
    case SmallTalk = 'smalltalk';
    case Unknown = 'unknown';

    /**
     * Normalize intent string from various sources (AI, legacy code).
     */
    public static function fromString(string $value): self
    {
        $normalized = str_replace(['_', '-', ' '], '', strtolower(trim($value)));
        
        return match ($normalized) {
            'productsearch', 'product', 'search' => self::ProductSearch,
            'productcomparison', 'comparison', 'compare' => self::ProductComparison,
            'orderstatus', 'order', 'status' => self::OrderStatus,
            'faq', 'info', 'shopinfo' => self::Faq,
            'smalltalk', 'small', 'talk', 'greeting' => self::SmallTalk,
            default => self::Unknown,
        };
    }

    /**
     * Check if intent is product search.
     */
    public function isProductSearch(): bool
    {
        return $this === self::ProductSearch;
    }

    /**
     * Check if intent is product comparison.
     */
    public function isProductComparison(): bool
    {
        return $this === self::ProductComparison;
    }

    /**
     * Check if intent is product-related (search, details, comparison).
     */
    public function isProductRelated(): bool
    {
        return in_array($this, [self::ProductSearch, self::ProductComparison]);
    }

    /**
     * Get human-readable label for logging.
     */
    public function label(): string
    {
        return match ($this) {
            self::ProductSearch => 'Пошук товарів',
            self::ProductComparison => 'Порівняння товарів',
            self::OrderStatus => 'Статус замовлення',
            self::Faq => 'FAQ / Інформація',
            self::SmallTalk => 'Smalltalk',
            self::Unknown => 'Невідомий',
        };
    }

    /**
     * Get default confidence for fallback scenarios.
     */
    public function defaultConfidence(): float
    {
        return match ($this) {
            self::ProductSearch => 0.8,
            self::ProductComparison => 0.85,
            self::OrderStatus => 0.9,
            self::Faq => 0.7,
            self::SmallTalk => 0.9,
            self::Unknown => 0.3,
        };
    }
}
