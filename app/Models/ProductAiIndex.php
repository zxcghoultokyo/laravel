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
        'usage',
        'materials',
        'standards',
        'slang',
        'keywords',
        'embedding',
        'raw_ai_json',
    ];

    protected $casts = [
        'usage'     => 'array',
        'materials' => 'array',
        'standards' => 'array',
        'slang'     => 'array',
        'keywords'  => 'array',
        'embedding' => 'array',
        'raw_ai_json' => 'array',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
