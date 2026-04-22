<?php

namespace App\Services\Agent\Tools;

use App\Services\Knowledge\KnowledgeBaseService;
use Illuminate\Support\Facades\Log;

/**
 * Agent tool that lets GPT pull compact tenant-specific knowledge snippets
 * (FAQ answers, sales scripts, etc.) on demand. Keeps the system prompt
 * token-efficient — we never dump the full knowledge base into it.
 */
class KnowledgeLookupTool
{
    public function __construct(
        private readonly KnowledgeBaseService $knowledge,
    ) {}

    /**
     * @param  array<string,mixed>  $args  query, types, limit
     * @return array{results: array<int,array<string,mixed>>, count: int}
     */
    public function lookup(array $args, ?int $tenantId = null): array
    {
        $query = trim((string) ($args['query'] ?? ''));
        if ($query === '') {
            return ['results' => [], 'count' => 0];
        }

        $types = $args['types'] ?? null;
        if (is_string($types)) {
            $types = array_filter(array_map('trim', explode(',', $types)));
        }
        $types = is_array($types) && $types !== [] ? array_values($types) : null;

        $limit = (int) ($args['limit'] ?? 3);
        $limit = max(1, min($limit, 5));

        $results = $this->knowledge->search($query, $types, $limit, $tenantId);

        Log::info('KnowledgeLookupTool: lookup', [
            'query' => $query,
            'types' => $types,
            'limit' => $limit,
            'tenant_id' => $tenantId,
            'found' => count($results),
        ]);

        // Bump usage counters so popular entries float to the top of ties.
        foreach ($results as $row) {
            if (isset($row['id'])) {
                $this->knowledge->incrementUsage((int) $row['id']);
            }
        }

        // Compact payload — strip score/articles from the GPT-facing response.
        $compact = array_map(static fn (array $r) => [
            'type' => $r['type'],
            'question' => $r['question'],
            'answer' => $r['answer'],
            'category' => $r['category'],
        ], $results);

        return [
            'results' => $compact,
            'count' => count($compact),
        ];
    }
}
