<?php

namespace App\Models;

use App\Models\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-tenant knowledge record — FAQ, product marketing hints, scripts.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $type faq | product_hint | script
 * @property string|null $question
 * @property string $answer
 * @property array|null $keywords
 * @property array|null $articles
 * @property string|null $category
 * @property string $language
 * @property int $priority
 * @property bool $is_active
 * @property int $usage_count
 * @property string|null $source
 * @property string|null $external_id
 */
class TenantKnowledge extends Model
{
    use BelongsToTenant;

    protected $table = 'tenant_knowledge_base';

    public const TYPE_FAQ = 'faq';

    public const TYPE_PRODUCT_HINT = 'product_hint';

    public const TYPE_SCRIPT = 'script';

    protected $fillable = [
        'tenant_id',
        'type',
        'question',
        'answer',
        'keywords',
        'articles',
        'category',
        'language',
        'priority',
        'is_active',
        'usage_count',
        'source',
        'external_id',
    ];

    protected function casts(): array
    {
        return [
            'keywords' => 'array',
            'articles' => 'array',
            'priority' => 'integer',
            'is_active' => 'boolean',
            'usage_count' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Records that mention any of the given product articles in the JSON list.
     */
    public function scopeForArticles(Builder $query, array $articles): Builder
    {
        $articles = array_values(array_filter(array_map('strval', $articles)));
        if ($articles === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($articles) {
            foreach ($articles as $article) {
                $q->orWhere('articles', 'like', '%"'.str_replace('%', '\\%', $article).'"%');
            }
        });
    }
}
