<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RozetkaProductAttributeValue extends Model
{
    protected $fillable = [
        'rozetka_product_id',
        'attribute_id',
        'attribute_name',
        'value_id',
        'value_text',
    ];

    protected function casts(): array
    {
        return [
            'attribute_id' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(RozetkaProduct::class, 'rozetka_product_id');
    }

    public function categoryAttribute(): BelongsTo
    {
        return $this->belongsTo(RozetkaCategoryAttribute::class, 'attribute_id', 'attribute_id');
    }
}
