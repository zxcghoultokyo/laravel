<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Scopes\TenantScope;

class ChatSession extends Model
{
    /**
     * Boot the model and add global tenant scope.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    protected $fillable = [
        'tenant_id',
        'session_id',
        'last_intent',
        'last_user_query',
        'messages_count',
        'language',
        'status',
        'meta',
        'last_message_at',
        'needs_human',
        'escalation_reason',
        'operator_id',
    ];

    protected $casts = [
        'meta' => 'array',
        'last_message_at' => 'datetime',
        'messages_count' => 'integer',
        'needs_human' => 'boolean',
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
