<?php

namespace App\Services\Session;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Unified session context management for chat.
 * Consolidates session handling from AgentOrchestrator and ChatService.
 */
class SessionContextService
{
    private const CONTEXT_TTL_HOURS = 6;
    private const SEARCH_STATE_TTL_HOURS = 2;

    /**
     * Build standardized session key.
     */
    public function buildSessionKey(?string $sessionId): string
    {
        if (!$sessionId) {
            return 'anonymous_' . Str::random(8);
        }

        // Normalize session ID (remove special chars, limit length)
        $normalized = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId);
        return substr($normalized, 0, 64);
    }

    /**
     * Generate new session ID if needed.
     */
    public function ensureSessionId(?string $sessionId): string
    {
        return $sessionId ?: (string) Str::uuid();
    }

    // ===================
    // CONTEXT (General session state)
    // ===================

    /**
     * Load session context.
     */
    public function loadContext(?string $sessionId): array
    {
        if (!$sessionId) {
            return $this->defaultContext();
        }

        $key = $this->contextCacheKey($sessionId);
        $ctx = Cache::get($key, []);

        return is_array($ctx) ? array_merge($this->defaultContext(), $ctx) : $this->defaultContext();
    }

    /**
     * Save session context (merges with existing).
     */
    public function saveContext(?string $sessionId, array $data): void
    {
        if (!$sessionId) {
            return;
        }

        $key = $this->contextCacheKey($sessionId);
        $existing = $this->loadContext($sessionId);
        $merged = array_merge($existing, $data);

        Cache::put($key, $merged, now()->addHours(self::CONTEXT_TTL_HOURS));
    }

    /**
     * Clear session context.
     */
    public function clearContext(?string $sessionId): void
    {
        if (!$sessionId) {
            return;
        }

        Cache::forget($this->contextCacheKey($sessionId));
    }

    /**
     * Get default context structure.
     */
    private function defaultContext(): array
    {
        return [
            'last_intent' => null,
            'last_query' => null,
            'last_refined_query' => null,
            'last_category' => null,
            'last_category_key' => null,
            'last_budget_min' => null,
            'last_budget_max' => null,
            'shown_products' => [],
            'last_shown_product_id' => null,
            'last_shown_product' => null,
            'last_chosen_ids' => [],
            'last_chosen_articles' => [],
            'ambiguous' => false,
            'slots' => [],
        ];
    }

    private function contextCacheKey(string $sessionId): string
    {
        return 'chat_ctx_' . $this->buildSessionKey($sessionId);
    }

    // ===================
    // SEARCH STATE (For multi-turn product search)
    // ===================

    /**
     * Load search state for multi-turn conversations.
     */
    public function loadSearchState(?string $sessionId): array
    {
        if (!$sessionId) {
            return $this->defaultSearchState();
        }

        $key = $this->searchStateCacheKey($sessionId);
        $state = Cache::get($key, []);

        return is_array($state) ? array_merge($this->defaultSearchState(), $state) : $this->defaultSearchState();
    }

    /**
     * Save search state.
     */
    public function saveSearchState(?string $sessionId, array $data): void
    {
        if (!$sessionId) {
            return;
        }

        $key = $this->searchStateCacheKey($sessionId);
        Cache::put($key, $data, now()->addHours(self::SEARCH_STATE_TTL_HOURS));
    }

    /**
     * Merge new data into existing search state.
     */
    public function mergeSearchState(?string $sessionId, ?string $categoryKey, array $slots, string $originalQuery): array
    {
        $state = $this->loadSearchState($sessionId);

        // Update category if new one provided
        if ($categoryKey) {
            $state['category_key'] = $categoryKey;
        }

        // Merge filters
        if (!empty($slots['budget_min'])) {
            $state['filters']['budget_min'] = $slots['budget_min'];
        }
        if (!empty($slots['budget_max'])) {
            $state['filters']['budget_max'] = $slots['budget_max'];
        }
        if (!empty($slots['camo'])) {
            $state['filters']['camo'] = $slots['camo'];
        }
        if (!empty($slots['color'])) {
            $state['filters']['color'] = $slots['color'];
        }

        // Auto-add negative terms for plate_carriers
        if ($state['category_key'] === 'plate_carriers') {
            $state['negative_terms'] = array_unique(array_merge(
                $state['negative_terms'] ?? [],
                ['панель', 'pouch', 'cummerbund', 'підсумок', 'кишеня']
            ));
        }

        return $state;
    }

    /**
     * Add shown product IDs to state (to avoid showing duplicates).
     */
    public function addShownIds(?string $sessionId, array $ids): void
    {
        if (!$sessionId || empty($ids)) {
            return;
        }

        $state = $this->loadSearchState($sessionId);
        $state['shown_ids'] = array_values(array_unique(
            array_merge($state['shown_ids'] ?? [], $ids)
        ));
        $this->saveSearchState($sessionId, $state);
    }

    /**
     * Clear search state.
     */
    public function clearSearchState(?string $sessionId): void
    {
        if (!$sessionId) {
            return;
        }

        Cache::forget($this->searchStateCacheKey($sessionId));
    }

    /**
     * Get default search state structure.
     */
    private function defaultSearchState(): array
    {
        return [
            'category_key' => null,
            'filters' => [
                'budget_min' => null,
                'budget_max' => null,
                'camo' => null,
                'color' => null,
            ],
            'negative_terms' => [],
            'shown_ids' => [],
            'last_question' => null,
        ];
    }

    private function searchStateCacheKey(string $sessionId): string
    {
        return 'chat_search_' . $this->buildSessionKey($sessionId);
    }

    // ===================
    // HELPER METHODS
    // ===================

    /**
     * Check if current request is a follow-up "show more" request.
     */
    public function isFollowupMoreRequest(string $message): bool
    {
        $lower = mb_strtolower(trim($message));
        $patterns = [
            'ще', 'більше', 'інші', 'інше', 'інший',
            'ещё', 'еще', 'больше',
            'more', 'show more', 'next',
            'далі', 'дальше',
        ];

        foreach ($patterns as $pattern) {
            if ($lower === $pattern || str_starts_with($lower, $pattern . ' ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user wants to skip questions and see products immediately.
     */
    public function shouldForceShowProducts(string $message, array $context): bool
    {
        $lower = mb_strtolower(trim($message));

        // Short messages likely want results, not questions
        if (mb_strlen($lower) < 15) {
            return true;
        }

        // Explicit keywords
        $forcePatterns = ['покажи', 'показати', 'що є', 'що маєте', 'list', 'варіанти'];
        foreach ($forcePatterns as $p) {
            if (str_contains($lower, $p)) {
                return true;
            }
        }

        // If user already answered a question, don't ask again
        if (!empty($context['last_question'])) {
            return true;
        }

        return false;
    }

    /**
     * Get combined context for agent (both general and search state).
     */
    public function getFullContext(?string $sessionId): array
    {
        return array_merge(
            $this->loadContext($sessionId),
            ['search_state' => $this->loadSearchState($sessionId)],
            ['session_id' => $sessionId]
        );
    }
}
