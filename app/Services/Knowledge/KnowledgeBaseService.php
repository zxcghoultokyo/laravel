<?php

namespace App\Services\Knowledge;

use App\Models\TenantKnowledge;
use App\Scopes\TenantScope;
use App\Services\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Retrieves tenant-scoped knowledge entries (FAQ, product hints, scripts)
 * using lightweight keyword + LIKE matching.
 *
 * No embeddings yet — datasets are small (~150 rows/tenant); LIKE + keyword
 * overlap scoring is accurate enough and cheap. Swap to vectors later if
 * recall drops.
 */
class KnowledgeBaseService
{
    /**
     * Very common Ukrainian stop words we ignore during tokenisation.
     * Keep it short — we rely on match count, not on linguistics.
     *
     * @var array<string,bool>
     */
    private const STOP_WORDS = [
        'і' => true, 'й' => true, 'та' => true, 'а' => true, 'але' => true,
        'або' => true, 'чи' => true, 'як' => true, 'що' => true, 'це' => true,
        'є' => true, 'не' => true, 'на' => true, 'в' => true, 'у' => true,
        'до' => true, 'з' => true, 'зі' => true, 'із' => true, 'для' => true,
        'про' => true, 'від' => true, 'при' => true, 'за' => true, 'по' => true,
        'так' => true, 'ні' => true, 'ви' => true, 'ти' => true, 'ми' => true,
        'вам' => true, 'нам' => true, 'мені' => true, 'тобі' => true,
        'будь' => true, 'ласка' => true, 'скажіть' => true, 'підкажіть' => true,
        'можна' => true, 'треба' => true, 'хочу' => true, 'хочемо' => true,
    ];

    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Search the knowledge base for entries relevant to a free-text query.
     *
     * @param  string  $query  free-text user question
     * @param  array<int,string>|null  $types  filter by type (faq, product_hint, script); null = all
     * @param  int  $limit  max results
     * @param  int|null  $tenantId  override tenant (falls back to current context)
     * @return array<int,array<string,mixed>> each item: id, type, question, answer, category, score
     */
    public function search(string $query, ?array $types = null, int $limit = 5, ?int $tenantId = null): array
    {
        $tenantId ??= $this->tenantContext->getTenantId();
        if (! $tenantId) {
            return [];
        }

        $tokens = $this->tokenize($query);
        if ($tokens === []) {
            return [];
        }

        $builder = $this->baseQuery($tenantId);
        if ($types) {
            $builder->whereIn('type', $types);
        }

        $builder->where(function (Builder $q) use ($tokens) {
            foreach ($tokens as $token) {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $token).'%';
                $q->orWhere('question', 'like', $like)
                    ->orWhere('answer', 'like', $like)
                    ->orWhere('keywords', 'like', $like);
            }
        });

        // Pull a generous candidate set, then rescore in PHP.
        $candidates = $builder
            ->orderByDesc('priority')
            ->orderByDesc('usage_count')
            ->limit(max($limit * 4, 20))
            ->get();

        $scored = $candidates
            ->map(fn (TenantKnowledge $row) => [
                'row' => $row,
                'score' => $this->score($row, $tokens),
            ])
            ->filter(fn (array $c) => $c['score'] > 0)
            ->sortByDesc('score')
            ->take($limit)
            ->values();

        return $scored->map(fn (array $c) => $this->format($c['row'], (float) $c['score']))->all();
    }

    /**
     * Return marketing hints keyed by product article for the given list.
     *
     * @param  array<int,string>  $articles
     * @return array<string,string> article => answer text
     */
    public function getHintsForArticles(array $articles, ?int $tenantId = null): array
    {
        $tenantId ??= $this->tenantContext->getTenantId();
        if (! $tenantId || $articles === []) {
            return [];
        }

        $articles = array_values(array_unique(array_filter(array_map('strval', $articles))));
        if ($articles === []) {
            return [];
        }

        $rows = $this->baseQuery($tenantId)
            ->where('type', TenantKnowledge::TYPE_PRODUCT_HINT)
            ->forArticles($articles)
            ->orderByDesc('priority')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            foreach ((array) $row->articles as $article) {
                $article = (string) $article;
                if ($article === '' || isset($map[$article])) {
                    continue;
                }
                if (in_array($article, $articles, true)) {
                    $map[$article] = (string) $row->answer;
                }
            }
        }

        return $map;
    }

    /**
     * Bump usage counter — called after an answer is surfaced to the user.
     */
    public function incrementUsage(int $id): void
    {
        try {
            TenantKnowledge::withoutGlobalScope(TenantScope::class)
                ->whereKey($id)
                ->increment('usage_count');
        } catch (\Throwable $e) {
            Log::warning('KnowledgeBaseService::incrementUsage failed', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function baseQuery(int $tenantId): Builder
    {
        return TenantKnowledge::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->active();
    }

    /**
     * Tokenize: lowercase, strip punctuation, drop stop-words & tokens < 3 chars.
     *
     * @return array<int,string>
     */
    private function tokenize(string $text): array
    {
        $text = mb_strtolower(trim($text));
        if ($text === '') {
            return [];
        }

        $parts = preg_split('/[^\p{L}\p{N}]+/u', $text) ?: [];
        $tokens = [];
        foreach ($parts as $p) {
            if ($p === '' || mb_strlen($p) < 3) {
                continue;
            }
            if (isset(self::STOP_WORDS[$p])) {
                continue;
            }
            $tokens[$p] = true;
        }

        return array_keys($tokens);
    }

    /**
     * Very simple scoring: +3 for each query token matching a stored keyword,
     * +2 for a match in the question, +1 in the answer. priority and usage
     * act as tie-breakers.
     *
     * @param  array<int,string>  $tokens
     */
    private function score(TenantKnowledge $row, array $tokens): float
    {
        $score = 0.0;
        $question = mb_strtolower((string) $row->question);
        $answer = mb_strtolower((string) $row->answer);
        $keywords = array_map('mb_strtolower', (array) $row->keywords);

        foreach ($tokens as $token) {
            if ($keywords && in_array($token, $keywords, true)) {
                $score += 3.0;
            }
            if ($question !== '' && str_contains($question, $token)) {
                $score += 2.0;
            }
            if ($answer !== '' && str_contains($answer, $token)) {
                $score += 1.0;
            }
        }

        // Gentle nudges for curated/popular entries.
        $score += min(2.0, $row->priority * 0.1);
        $score += min(1.0, $row->usage_count * 0.01);

        return $score;
    }

    /**
     * @return array<string,mixed>
     */
    private function format(TenantKnowledge $row, float $score): array
    {
        return [
            'id' => $row->id,
            'type' => $row->type,
            'question' => $row->question,
            'answer' => $row->answer,
            'category' => $row->category,
            'articles' => $row->articles,
            'score' => round($score, 2),
        ];
    }
}
