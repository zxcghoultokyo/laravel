<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HoroshopProduct extends Model
{
    protected $fillable = [
        'tenant_id',
        'article',
        'parent_article',
        'title',
        'title_json',
        'price',
        'price_old',
        'brand',
        'color',
        'size',
        'category_path',
        'in_stock',
        'quantity',
        'presence',
        'display_in_showcase',
        'description_ua',
        'description_ru',
        'short_description_ua',
        'short_description_ru',
        'images',
        'gallery_common',
        'characteristics',
        'seo_title',
        'seo_keywords',
        'seo_description',
        'slug',
        'link',
        'popularity',
        'we_recommended',
        'icons',
        'mod_title',
        'raw',
        'rozetka_product_id',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'price_old' => 'decimal:2',
            'in_stock' => 'boolean',
            'quantity' => 'integer',
            'display_in_showcase' => 'boolean',
            'we_recommended' => 'boolean',
            'popularity' => 'integer',
            'title_json' => 'array',
            'images' => 'array',
            'gallery_common' => 'array',
            'characteristics' => 'array',
            'seo_title' => 'array',
            'seo_keywords' => 'array',
            'seo_description' => 'array',
            'icons' => 'array',
            'raw' => 'array',
            'synced_at' => 'datetime',
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

    public function rozetkaProduct(): BelongsTo
    {
        return $this->belongsTo(RozetkaProduct::class);
    }

    public function getFirstImageAttribute(): ?string
    {
        $imgs = $this->images;

        if (is_array($imgs) && ! empty($imgs)) {
            $first = $imgs[0];

            return is_string($first) ? $first : ($first['url'] ?? $first['link'] ?? null);
        }

        return null;
    }
}
