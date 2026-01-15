<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Store context for auto-generating AI prompts.
 * 
 * Contains analyzed data about the store: categories, brands, price segments,
 * and extracted knowledge from FAQ pages for prompt generation.
 */
class StoreContext extends Model
{
    use HasFactory;

    protected $fillable = [
        'widget_settings_id',
        
        // Auto-detected
        'store_type',
        'primary_categories',
        'brands',
        'price_segments',
        'catalog_size',
        
        // From FAQ/Policies
        'delivery_info',
        'payment_info',
        'return_policy',
        'warranty_info',
        'working_hours',
        
        // For prompt
        'expertise_areas',
        'common_questions',
        'product_tips',
        
        // Generated
        'generated_prompt',
        'prompt_version',
        'last_analyzed_at',
    ];

    protected $casts = [
        'primary_categories' => 'array',
        'brands' => 'array',
        'price_segments' => 'array',
        'expertise_areas' => 'array',
        'common_questions' => 'array',
        'product_tips' => 'array',
        'last_analyzed_at' => 'datetime',
    ];

    /**
     * Store type constants
     */
    public const TYPE_TACTICAL = 'tactical';
    public const TYPE_FASHION = 'fashion';
    public const TYPE_ELECTRONICS = 'electronics';
    public const TYPE_SPORTS = 'sports';
    public const TYPE_GENERAL = 'general';

    /**
     * Catalog size constants
     */
    public const SIZE_SMALL = 'small';    // < 100 products
    public const SIZE_MEDIUM = 'medium';  // 100-1000 products
    public const SIZE_LARGE = 'large';    // > 1000 products

    /**
     * Widget settings relationship.
     */
    public function widgetSettings(): BelongsTo
    {
        return $this->belongsTo(WidgetSettings::class);
    }

    /**
     * Get top categories (limited).
     */
    public function getTopCategories(int $limit = 10): array
    {
        return array_slice($this->primary_categories ?? [], 0, $limit);
    }

    /**
     * Get top brands (limited).
     */
    public function getTopBrands(int $limit = 10): array
    {
        return array_slice($this->brands ?? [], 0, $limit);
    }

    /**
     * Get price segment label.
     */
    public function getPriceSegmentLabel(string $segment): ?string
    {
        $segments = $this->price_segments ?? [];
        
        return match($segment) {
            'budget' => isset($segments['budget']) ? "до {$segments['budget']} грн" : null,
            'mid' => isset($segments['budget'], $segments['mid']) 
                ? "{$segments['budget']}-{$segments['mid']} грн" : null,
            'premium' => isset($segments['mid']) ? "від {$segments['mid']} грн" : null,
            default => null,
        };
    }

    /**
     * Check if context needs refresh (older than 24h).
     */
    public function needsRefresh(): bool
    {
        if (!$this->last_analyzed_at) {
            return true;
        }
        
        return $this->last_analyzed_at->diffInHours(now()) > 24;
    }

    /**
     * Get store type label in Ukrainian.
     */
    public function getStoreTypeLabel(): string
    {
        return match($this->store_type) {
            self::TYPE_TACTICAL => 'Тактичний магазин',
            self::TYPE_FASHION => 'Магазин одягу',
            self::TYPE_ELECTRONICS => 'Електроніка',
            self::TYPE_SPORTS => 'Спорттовари',
            default => 'Загальний магазин',
        };
    }
}
