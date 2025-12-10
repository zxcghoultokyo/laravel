<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductAiIndex extends Model
{
    protected $table = 'product_ai_index';

    protected $fillable = [
        'product_id',
        'product_type',
        'ai_category',
        'materials',
        'standards',
        'slang',
        'keywords',
        'usage',
        'embedding',
    ];

    protected $casts = [
        'embedding' => 'array',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
