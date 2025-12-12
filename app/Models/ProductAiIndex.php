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
        'raw_ai_json',
    ];

    protected $casts = [
        'materials'   => 'array',
        'standards'   => 'array',
        'slang'       => 'array',
        'keywords'    => 'array',
        'usage'       => 'array',
        'embedding'   => 'array',
        'raw_ai_json' => 'array',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
