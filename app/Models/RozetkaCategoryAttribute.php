<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RozetkaCategoryAttribute extends Model
{
    protected $fillable = [
        'rozetka_category_id',
        'attribute_id',
        'name',
        'attr_type',
        'filter_type',
        'unit',
        'is_global',
        'values',
    ];

    protected function casts(): array
    {
        return [
            'rozetka_category_id' => 'integer',
            'attribute_id' => 'integer',
            'is_global' => 'boolean',
            'values' => 'array',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(RozetkaCategory::class, 'rozetka_category_id', 'rozetka_id');
    }

    public function isMainFilter(): bool
    {
        return $this->filter_type === 'main';
    }

    public function hasDropdownValues(): bool
    {
        return in_array($this->attr_type, ['ComboBox', 'ListValues', 'List', 'CheckBoxGroupValues']);
    }
}
