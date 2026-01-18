<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use App\Models\ProductAiIndex;
use App\Scopes\TenantScope;

class Product extends Model
{
    use SoftDeletes;

    /**
     * Boot the model and add global tenant scope.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    protected $fillable = [
        'tenant_id',
        'article',
        'parent_article',
        'title',
        'title_json',
        'price',
        'price_old',
        'category_path',
        'slug',
        'link',
        'images',
        'raw',
        'search_index',
        'orders_count',
        'views_count',
        'added_to_cart_count',
        'display_in_showcase',
        'in_stock',
        'presence',
        'quantity',
        'popularity',
        'we_recommended',
        'color',
        'brand',
    ];

    protected $casts = [
        'title_json'           => 'array',
        'images'               => 'array',
        'raw'                  => 'array',
        'orders_count'         => 'integer',
        'views_count'          => 'integer',
        'added_to_cart_count'  => 'integer',
        'display_in_showcase'  => 'boolean',
        'we_recommended'       => 'boolean',
        'in_stock'             => 'boolean',
        'quantity'             => 'integer',
        'popularity'           => 'integer',
        'presence'             => 'string',
        'color'                => 'string',
        'brand' => 'string',
    ];
    
    /**
     * Tenant this product belongs to.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
    
    public function tags()
    {
        return $this->belongsToMany(\App\Models\ProductTag::class, 'product_product_tag');
    }
    
    public function aiIndex()
    {
        return $this->hasOne(ProductAiIndex::class, 'product_id');
    }
    
    public function crossSells()
    {
        return $this->hasMany(ProductCrossSell::class);
    }
    
    public function crossSellProducts()
    {
        return $this->belongsToMany(Product::class, 'product_cross_sells', 'product_id', 'cross_sell_product_id')
            ->withPivot(['type', 'reason', 'priority', 'is_active'])
            ->wherePivot('is_active', true)
            ->orderByPivot('priority', 'desc');
    }
}
