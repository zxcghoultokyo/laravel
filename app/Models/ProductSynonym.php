<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSynonym extends Model
{
    // NOTE: We do NOT use BelongsToTenant trait here because:
    // 1. tenant_id can be NULL (global synonyms)
    // 2. We want to query both tenant-specific AND global synonyms
    
    protected $table = 'product_synonyms';

    protected $fillable = [
        'tenant_id',
        // Реляційна схема (ВАРІАНТ A):
        // product_type = канонічний тип товару (наприклад: "плитоноска")
        // synonym      = конкретний варіант, який може бути в запиті (наприклад: "бронік", "tq")
        'product_type',
        'synonym',
        'language',
        'weight',
        'domain',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'weight'    => 'float',
    ];
    
    /**
     * Get synonyms for a tenant (includes global synonyms with tenant_id = NULL)
     */
    public static function forTenant(?int $tenantId): \Illuminate\Database\Eloquent\Builder
    {
        return static::query()
            ->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)
                  ->orWhereNull('tenant_id'); // Include global synonyms
            })
            ->where('is_active', true);
    }
    
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
