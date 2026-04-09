<?php

namespace App\Services\Usage;

use App\Models\AiUsageLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AiCostTrackingService
{
    /**
     * Cost per 1M tokens (USD) by model.
     * Updated: 2026-04 pricing.
     */
    private const MODEL_PRICING = [
        'gpt-4o' => ['input' => 2.50, 'output' => 10.00],
        'gpt-4o-2024-11-20' => ['input' => 2.50, 'output' => 10.00],
        'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
        'gpt-4o-mini-2024-07-18' => ['input' => 0.15, 'output' => 0.60],
        'gpt-4.1' => ['input' => 2.00, 'output' => 8.00],
        'gpt-4.1-mini' => ['input' => 0.40, 'output' => 1.60],
        'gpt-4.1-nano' => ['input' => 0.10, 'output' => 0.40],
        'o3-mini' => ['input' => 1.10, 'output' => 4.40],
    ];

    /**
     * Log an OpenAI API call.
     */
    public function log(
        string $source,
        string $model,
        array $usage,
        ?int $tenantId = null,
        ?string $sessionId = null,
        ?int $responseTimeMs = null,
        bool $isError = false,
        ?string $endpoint = 'chat/completions'
    ): void {
        try {
            $promptTokens = $usage['prompt_tokens'] ?? 0;
            $completionTokens = $usage['completion_tokens'] ?? 0;
            $totalTokens = $usage['total_tokens'] ?? ($promptTokens + $completionTokens);
            $costUsd = $this->calculateCost($model, $promptTokens, $completionTokens);

            AiUsageLog::create([
                'tenant_id' => $tenantId,
                'source' => $source,
                'model' => $model,
                'session_id' => $sessionId,
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $totalTokens,
                'cost_usd' => $costUsd,
                'endpoint' => $endpoint,
                'response_time_ms' => $responseTimeMs,
                'is_error' => $isError,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to log AI usage', [
                'error' => $e->getMessage(),
                'source' => $source,
                'model' => $model,
            ]);
        }
    }

    /**
     * Calculate cost in USD based on model pricing.
     */
    public function calculateCost(string $model, int $promptTokens, int $completionTokens): float
    {
        $pricing = self::MODEL_PRICING[$model] ?? null;

        if (! $pricing) {
            // Try prefix match (e.g. gpt-4o-2024-08-06 → gpt-4o)
            foreach (self::MODEL_PRICING as $prefix => $p) {
                if (str_starts_with($model, $prefix)) {
                    $pricing = $p;
                    break;
                }
            }
        }

        if (! $pricing) {
            // Default to gpt-4o pricing as safe upper bound
            $pricing = self::MODEL_PRICING['gpt-4o'];
        }

        return ($promptTokens * $pricing['input'] / 1_000_000)
             + ($completionTokens * $pricing['output'] / 1_000_000);
    }

    /**
     * Get usage stats for a tenant in a date range.
     */
    public function getStats(?int $tenantId = null, ?string $from = null, ?string $to = null): array
    {
        $query = AiUsageLog::query();

        if ($tenantId) {
            $query->forTenant($tenantId);
        }

        if ($from) {
            $query->where('created_at', '>=', $from);
        }

        if ($to) {
            $query->where('created_at', '<=', $to);
        }

        $totals = (clone $query)->selectRaw('
            COUNT(*) as total_requests,
            SUM(prompt_tokens) as total_prompt_tokens,
            SUM(completion_tokens) as total_completion_tokens,
            SUM(total_tokens) as total_tokens,
            SUM(cost_usd) as total_cost_usd,
            AVG(response_time_ms) as avg_response_time_ms,
            SUM(CASE WHEN is_error = 1 THEN 1 ELSE 0 END) as error_count
        ')->first();

        $byModel = (clone $query)->selectRaw('
            model,
            COUNT(*) as requests,
            SUM(total_tokens) as tokens,
            SUM(cost_usd) as cost_usd
        ')->groupBy('model')->get();

        $bySource = (clone $query)->selectRaw('
            source,
            COUNT(*) as requests,
            SUM(total_tokens) as tokens,
            SUM(cost_usd) as cost_usd
        ')->groupBy('source')->get();

        $dailyCosts = (clone $query)->selectRaw('
            DATE(created_at) as date,
            COUNT(*) as requests,
            SUM(cost_usd) as cost_usd
        ')->groupBy(DB::raw('DATE(created_at)'))->orderBy('date', 'desc')->limit(30)->get();

        return [
            'totals' => [
                'requests' => (int) $totals->total_requests,
                'prompt_tokens' => (int) $totals->total_prompt_tokens,
                'completion_tokens' => (int) $totals->total_completion_tokens,
                'total_tokens' => (int) $totals->total_tokens,
                'cost_usd' => round((float) $totals->total_cost_usd, 4),
                'cost_uah' => round((float) $totals->total_cost_usd * 41.5, 2), // approx rate
                'avg_response_time_ms' => round((float) $totals->avg_response_time_ms),
                'error_count' => (int) $totals->error_count,
            ],
            'by_model' => $byModel->map(fn ($r) => [
                'model' => $r->model,
                'requests' => (int) $r->requests,
                'tokens' => (int) $r->tokens,
                'cost_usd' => round((float) $r->cost_usd, 4),
            ])->values(),
            'by_source' => $bySource->map(fn ($r) => [
                'source' => $r->source,
                'requests' => (int) $r->requests,
                'tokens' => (int) $r->tokens,
                'cost_usd' => round((float) $r->cost_usd, 4),
            ])->values(),
            'daily' => $dailyCosts->map(fn ($r) => [
                'date' => $r->date,
                'requests' => (int) $r->requests,
                'cost_usd' => round((float) $r->cost_usd, 4),
            ])->values(),
        ];
    }

    /**
     * Get stats grouped by tenant.
     */
    public function getStatsByTenant(?string $from = null, ?string $to = null): array
    {
        $query = AiUsageLog::query();

        if ($from) {
            $query->where('created_at', '>=', $from);
        }
        if ($to) {
            $query->where('created_at', '<=', $to);
        }

        return $query->selectRaw('
            tenant_id,
            COUNT(*) as requests,
            SUM(total_tokens) as tokens,
            SUM(cost_usd) as cost_usd
        ')
            ->groupBy('tenant_id')
            ->orderByDesc(DB::raw('SUM(cost_usd)'))
            ->get()
            ->map(fn ($r) => [
                'tenant_id' => $r->tenant_id,
                'requests' => (int) $r->requests,
                'tokens' => (int) $r->tokens,
                'cost_usd' => round((float) $r->cost_usd, 4),
            ])
            ->values()
            ->toArray();
    }
}
