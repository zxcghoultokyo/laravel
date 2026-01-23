<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Category extends Model
{
    protected $table = 'categories';

    protected $fillable = [
        'tenant_id',
        'path',
        'path_norm',
        'slug',
        'products_count',
        'is_active',
    ];

    protected $casts = [
        'products_count' => 'integer',
        'is_active'      => 'boolean',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(CategoryAlias::class, 'category_id');
    }
}
