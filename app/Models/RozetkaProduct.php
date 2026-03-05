<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RozetkaProduct extends Model
{
    protected $fillable = [
        'tenant_id',
        'rozetka_item_id',
        'article',
        'parent_article',
        'title',
        'price',
        'price_old',
        'rozetka_category_id',
        'rozetka_category_name',
        'in_stock',
        'quantity',
        'moderation_status',
        'status',
        'group_id',
        'photos',
        'raw',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'rozetka_item_id' => 'integer',
            'rozetka_category_id' => 'integer',
            'price' => 'decimal:2',
            'price_old' => 'decimal:2',
            'in_stock' => 'boolean',
            'quantity' => 'integer',
            'moderation_status' => 'integer',
            'group_id' => 'integer',
            'photos' => 'array',
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

    public function rozetkaCategory(): BelongsTo
    {
        return $this->belongsTo(RozetkaCategory::class, 'rozetka_category_id', 'rozetka_id');
    }

    public function attributeValues(): HasMany
    {
        return $this->hasMany(RozetkaProductAttributeValue::class);
    }

    public function getFirstPhotoAttribute(): ?string
    {
        $urls = $this->clean_photo_urls;

        return $urls[0] ?? null;
    }

    /**
     * @return string[]
     */
    public function getCleanPhotoUrlsAttribute(): array
    {
        $photos = $this->photos;

        if (! is_array($photos)) {
            return [];
        }

        $urls = [];
        foreach ($photos as $photo) {
            $url = $this->extractUrlString($photo);
            if ($url) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    protected function extractUrlString(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if (! is_array($value)) {
            return null;
        }

        foreach (['url', 'original', 'thumbnail', 'big', 'small', 'photo'] as $key) {
            if (isset($value[$key])) {
                $result = $this->extractUrlString($value[$key]);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        return null;
    }

    /**
     * Match with local Horoshop product by article.
     */
    public function localProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'article', 'article');
    }
}
