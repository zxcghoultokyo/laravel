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
        'upload_status',
        'upload_status_title',
        'rz_status',
        'rz_sell_status',
        'available',
        'available_title',
        'blocked_reasons',
        'change_status',
        'producer_name',
        'url',
        'status',
        'export_status',
        'local_product_id',
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
            'upload_status' => 'integer',
            'rz_status' => 'integer',
            'rz_sell_status' => 'integer',
            'available' => 'integer',
            'change_status' => 'integer',
            'group_id' => 'integer',
            'blocked_reasons' => 'array',
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
            if (is_string($photo)) {
                $urls[] = $photo;
            } elseif (is_array($photo)) {
                $url = $this->extractUrlString($photo);
                if ($url) {
                    $urls[] = $url;
                }
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
     * Match with local Horoshop product by local_product_id or article.
     */
    public function localProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'local_product_id');
    }

    public function isOnRozetka(): bool
    {
        return $this->rozetka_item_id !== null;
    }

    public function isDraft(): bool
    {
        return $this->export_status === 'draft';
    }

    public function isReady(): bool
    {
        return $this->export_status === 'ready';
    }
}
