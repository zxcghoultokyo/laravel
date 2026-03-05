<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RozetkaCategory extends Model
{
    protected $fillable = [
        'rozetka_id',
        'title_ua',
        'title_ru',
        'parent_rozetka_id',
        'level',
        'mpath',
        'full_path',
        'is_vendor_required',
    ];

    protected function casts(): array
    {
        return [
            'rozetka_id' => 'integer',
            'parent_rozetka_id' => 'integer',
            'level' => 'integer',
            'is_vendor_required' => 'boolean',
        ];
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_rozetka_id', 'rozetka_id');
    }

    public function attributes(): HasMany
    {
        return $this->hasMany(RozetkaCategoryAttribute::class, 'rozetka_category_id', 'rozetka_id');
    }
}
