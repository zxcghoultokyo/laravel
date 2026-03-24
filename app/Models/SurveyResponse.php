<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SurveyResponse extends Model
{
    protected $fillable = [
        'tenant_id',
        'overall_rating',
        'recommendation_accuracy',
        'problems',
        'tone_feedback',
        'business_impact',
        'best_feature',
        'missing_feature',
        'willingness_to_pay',
        'nps_score',
        'open_comment',
    ];

    protected function casts(): array
    {
        return [
            'problems' => 'array',
            'business_impact' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
