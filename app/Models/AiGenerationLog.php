<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiGenerationLog extends Model
{
    protected $table = 'ai_generation_logs';

    public $timestamps = false; // created_at only

    protected $fillable = [
        'entity_type', 'entity_ref', 'domain', 'language',
        'prompt_hash', 'input_excerpt', 'raw_ai_json',
        'status', 'error_message', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
