<?php

namespace App\DTO;

use App\Enums\Intent;

/**
 * DTO for agent response (final output to ChatService/API).
 */
readonly class AgentResponseDTO
{
    public function __construct(
        public string $message,
        public array $products,
        public Intent $intent,
        public bool $ambiguous,
        public ?string $refinedQuery,
        public array $filters,
        public array $chosenIds,
        public array $searchDebug,
        public array $extra = [],
    ) {}

    /**
     * Create product search response.
     */
    public static function productSearch(
        string $message,
        array $products,
        ?string $refinedQuery = null,
        array $filters = [],
        array $chosenIds = [],
        bool $ambiguous = false,
        array $searchDebug = [],
    ): self {
        return new self(
            message: $message,
            products: $products,
            intent: Intent::ProductSearch,
            ambiguous: $ambiguous,
            refinedQuery: $refinedQuery,
            filters: $filters,
            chosenIds: $chosenIds,
            searchDebug: $searchDebug,
        );
    }

    /**
     * Create order status response.
     */
    public static function orderStatus(
        string $message,
        array $orders = [],
        array $criteria = [],
        int $found = 0,
    ): self {
        return new self(
            message: $message,
            products: [],
            intent: Intent::OrderStatus,
            ambiguous: false,
            refinedQuery: null,
            filters: [],
            chosenIds: [],
            searchDebug: [],
            extra: [
                'orders' => $orders,
                'criteria' => $criteria,
                'found' => $found,
            ],
        );
    }

    /**
     * Create FAQ response.
     */
    public static function faq(string $message, ?string $topic = null): self
    {
        return new self(
            message: $message,
            products: [],
            intent: Intent::Faq,
            ambiguous: false,
            refinedQuery: null,
            filters: [],
            chosenIds: [],
            searchDebug: [],
            extra: ['topic' => $topic],
        );
    }

    /**
     * Create smalltalk response.
     */
    public static function smallTalk(string $message): self
    {
        return new self(
            message: $message,
            products: [],
            intent: Intent::SmallTalk,
            ambiguous: false,
            refinedQuery: null,
            filters: [],
            chosenIds: [],
            searchDebug: [],
        );
    }

    /**
     * Create unknown/error response.
     */
    public static function unknown(string $message): self
    {
        return new self(
            message: $message,
            products: [],
            intent: Intent::Unknown,
            ambiguous: true,
            refinedQuery: null,
            filters: [],
            chosenIds: [],
            searchDebug: [],
        );
    }

    /**
     * Create no results response.
     */
    public static function noResults(string $query, ?string $suggestion = null): self
    {
        $message = "На жаль, не знайшов товарів за запитом «{$query}».";
        
        if ($suggestion) {
            $message .= "\n\n{$suggestion}";
        } else {
            $message .= " Спробуйте інші ключові слова або опишіть що саме вам потрібно.";
        }
        
        return new self(
            message: $message,
            products: [],
            intent: Intent::ProductSearch,
            ambiguous: false,
            refinedQuery: $query,
            filters: [],
            chosenIds: [],
            searchDebug: ['candidates_found' => 0],
        );
    }

    /**
     * Convert to legacy array format (for backward compatibility).
     */
    public function toArray(): array
    {
        return [
            'message' => $this->message,
            'products' => $this->products,
            'meta' => array_merge([
                'intent' => $this->intent->value,
                'ambiguous' => $this->ambiguous,
                'refined_query' => $this->refinedQuery,
                'filters' => $this->filters,
                'chosen_ids' => $this->chosenIds,
                'search_debug' => $this->searchDebug,
            ], $this->extra),
        ];
    }

    /**
     * Check if response has products.
     */
    public function hasProducts(): bool
    {
        return !empty($this->products);
    }

    /**
     * Get products count.
     */
    public function productsCount(): int
    {
        return count($this->products);
    }

    /**
     * Get response type for frontend.
     */
    public function responseType(): string
    {
        if ($this->hasProducts()) {
            return 'products';
        }
        return 'text';
    }

    /**
     * Add follow-up question to message.
     */
    public function withFollowUp(string $question): self
    {
        if (empty(trim($question))) {
            return $this;
        }

        return new self(
            message: $this->message . "\n\n" . $question,
            products: $this->products,
            intent: $this->intent,
            ambiguous: $this->ambiguous,
            refinedQuery: $this->refinedQuery,
            filters: $this->filters,
            chosenIds: $this->chosenIds,
            searchDebug: $this->searchDebug,
            extra: $this->extra,
        );
    }
}
