<?php

namespace App\Enums;

/**
 * Chat intent types for agent classification.
 */
enum Intent: string
{
    case ProductSearch = 'product_search';
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
     * Check if intent is product-related (search, details, comparison).
     */
    public function isProductRelated(): bool
    {
        return $this === self::ProductSearch;
    }

    /**
     * Get human-readable label for logging.
     */
    public function label(): string
    {
        return match ($this) {
            self::ProductSearch => 'Пошук товарів',
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
            self::OrderStatus => 0.9,
            self::Faq => 0.7,
            self::SmallTalk => 0.9,
            self::Unknown => 0.3,
        };
    }
}
