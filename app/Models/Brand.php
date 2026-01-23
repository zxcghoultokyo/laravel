<?php

namespace App\Models;

use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Brand extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'product_count',
        'is_active',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'product_count' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Apply tenant scope for multi-tenancy
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    /**
     * Tenant relationship
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Generate slug from name
     */
    public static function boot()
    {
        parent::boot();

        static::creating(function ($brand) {
            if (empty($brand->slug)) {
                $slug = Str::slug($brand->name);
                
                // Check if slug already exists for this tenant
                $count = 1;
                $originalSlug = $slug;
                
                $query = Brand::withoutGlobalScopes()->where('slug', $slug);
                if ($brand->tenant_id) {
                    $query->where('tenant_id', $brand->tenant_id);
                }
                
                while ($query->exists()) {
                    $slug = $originalSlug . '-' . $count;
                    $count++;
                    
                    $query = Brand::withoutGlobalScopes()->where('slug', $slug);
                    if ($brand->tenant_id) {
                        $query->where('tenant_id', $brand->tenant_id);
                    }
                }
                
                $brand->slug = $slug;
            }
        });
    }

    /**
     * Get products with this brand
     */
    public function products()
    {
        return $this->hasMany(Product::class, 'brand', 'name');
    }
}
