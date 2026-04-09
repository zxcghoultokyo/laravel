<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiUsageLog extends Model
{
    protected $fillable = [
        'tenant_id',
        'source',
        'model',
        'session_id',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'cost_usd',
        'endpoint',
        'response_time_ms',
        'is_error',
    ];

    protected function casts(): array
    {
        return [
            'cost_usd' => 'decimal:6',
            'is_error' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeForSource($query, string $source)
    {
        return $query->where('source', $source);
    }

    public function scopeForModel($query, string $model)
    {
        return $query->where('model', $model);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('created_at', now()->toDateString());
    }

    public function scopeThisMonth($query)
    {
        return $query->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year);
    }
}
