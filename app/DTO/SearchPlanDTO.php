<?php

namespace App\DTO;

use App\Enums\Intent;

/**
 * DTO for agent search plan (result of AI classification).
 */
readonly class SearchPlanDTO
{
    public function __construct(
        public Intent $intent,
        public ?string $searchQuery,
        public array $filters,
        public bool $ambiguous,
        public float $confidence,
        public ?string $orderId = null,
        public bool $needsHuman = false,
        public ?string $escalationReason = null,
    ) {}

    /**
     * Create from array (e.g., from AI classification result).
     */
    public static function fromArray(array $data): self
    {
        $intent = isset($data['intent']) 
            ? Intent::fromString($data['intent']) 
            : Intent::Unknown;

        return new self(
            intent: $intent,
            searchQuery: $data['search_query'] ?? $data['normalized_query'] ?? null,
            filters: $data['filters'] ?? [],
            ambiguous: (bool) ($data['ambiguous'] ?? false),
            confidence: (float) ($data['confidence'] ?? $intent->defaultConfidence()),
            orderId: $data['order_id'] ?? null,
            needsHuman: (bool) ($data['needs_human'] ?? false),
            escalationReason: $data['escalation_reason'] ?? null,
        );
    }

    /**
     * Create fallback plan for product search (when AI is unavailable).
     */
    public static function fallbackProductSearch(string $message): self
    {
        return new self(
            intent: Intent::ProductSearch,
            searchQuery: $message,
            filters: [],
            ambiguous: true,
            confidence: 0.5,
        );
    }

    /**
     * Convert to array for logging/serialization.
     */
    public function toArray(): array
    {
        return [
            'intent' => $this->intent->value,
            'search_query' => $this->searchQuery,
            'filters' => $this->filters,
            'ambiguous' => $this->ambiguous,
            'confidence' => $this->confidence,
            'order_id' => $this->orderId,
            'needs_human' => $this->needsHuman,
            'escalation_reason' => $this->escalationReason,
        ];
    }

    /**
     * Check if plan is for product search.
     */
    public function isProductSearch(): bool
    {
        return $this->intent === Intent::ProductSearch;
    }

    /**
     * Check if plan is for order status.
     */
    public function isOrderStatus(): bool
    {
        return $this->intent === Intent::OrderStatus;
    }

    /**
     * Get merged filters with new values.
     */
    public function withFilters(array $additionalFilters): self
    {
        return new self(
            intent: $this->intent,
            searchQuery: $this->searchQuery,
            filters: array_merge($this->filters, $additionalFilters),
            ambiguous: $this->ambiguous,
            confidence: $this->confidence,
            orderId: $this->orderId,
        );
    }
}
