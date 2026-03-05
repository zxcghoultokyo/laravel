<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RozetkaCategoryMapping extends Model
{
    protected $fillable = [
        'tenant_id',
        'local_category_id',
        'local_category_name',
        'local_category_source',
        'rozetka_category_id',
        'rozetka_category_name',
        'rozetka_category_path',
        'is_confirmed',
        'matched_by',
    ];

    protected function casts(): array
    {
        return [
            'is_confirmed' => 'boolean',
            'rozetka_category_id' => 'integer',
            'local_category_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function rozetkaCategory(): BelongsTo
    {
        return $this->belongsTo(RozetkaCategory::class, 'rozetka_category_id', 'rozetka_id');
    }
}
