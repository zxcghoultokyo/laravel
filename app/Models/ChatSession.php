<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatSession extends Model
{
    protected $fillable = [
        'session_id',
        'last_intent',
        'last_user_query',
        'messages_count',
        'language',
        'status',
        'meta',
        'last_message_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'last_message_at' => 'datetime',
        'messages_count' => 'integer',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    public function scopeClosed($query)
    {
        return $query->where('status', 'closed');
    }

    public function scopeFlagged($query)
    {
        return $query->where('status', 'flagged');
    }
}
