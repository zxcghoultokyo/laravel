<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Canned Response - pre-written responses for operators.
 * 
 * @property int $id
 * @property int $tenant_id
 * @property string $title
 * @property string $content
 * @property string|null $shortcut
 * @property string|null $category
 * @property int $usage_count
 * @property bool $is_active
 * @property array|null $variables
 * @property \DateTime $created_at
 * @property \DateTime $updated_at
 */
class CannedResponse extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'title',
        'content',
        'shortcut',
        'category',
        'usage_count',
        'is_active',
        'variables',
    ];

    protected $casts = [
        'usage_count' => 'integer',
        'is_active' => 'boolean',
        'variables' => 'array',
    ];

    /**
     * Common categories.
     */
    public const CATEGORY_GREETING = 'greeting';
    public const CATEGORY_FAREWELL = 'farewell';
    public const CATEGORY_DELIVERY = 'delivery';
    public const CATEGORY_PAYMENT = 'payment';
    public const CATEGORY_RETURNS = 'returns';
    public const CATEGORY_PRODUCT = 'product';
    public const CATEGORY_OTHER = 'other';

    /**
     * Get tenant.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Increment usage count.
     */
    public function incrementUsage(): void
    {
        $this->increment('usage_count');
    }

    /**
     * Process content with variables.
     */
    public function processContent(array $context = []): string
    {
        $content = $this->content;
        
        // Replace variables like {{customer_name}}, {{order_id}}, etc.
        foreach ($context as $key => $value) {
            $content = str_replace("{{" . $key . "}}", $value, $content);
        }
        
        // Remove any unreplaced variables
        $content = preg_replace('/\{\{[^}]+\}\}/', '', $content);
        
        return trim($content);
    }

    /**
     * Extract variables from content.
     */
    public function extractVariables(): array
    {
        preg_match_all('/\{\{([^}]+)\}\}/', $this->content, $matches);
        return $matches[1] ?? [];
    }

    /**
     * Scope: active responses.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: by category.
     */
    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Scope: search by shortcut or title.
     */
    public function scopeSearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('shortcut', 'like', "%{$search}%")
              ->orWhere('title', 'like', "%{$search}%");
        });
    }

    /**
     * Scope: popular (most used).
     */
    public function scopePopular($query)
    {
        return $query->orderByDesc('usage_count');
    }
}
