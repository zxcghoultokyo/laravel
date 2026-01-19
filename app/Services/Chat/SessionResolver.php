<?php

namespace App\Services\Chat;

use App\Models\ChatSession;
use Illuminate\Support\Facades\DB;

/**
 * Session Resolver - унифікує роботу з session identifiers
 * 
 * NAMING CONVENTION:
 * ==================
 * 
 * | Назва | Тип | Опис | Приклад |
 * |-------|-----|------|---------|
 * | session_id | string | Публічний ID сесії з widget | "session_1768827245332_z8lvumz8k" |
 * | chat_session_id | int | FK до chat_sessions.id | 42 |
 * | session | ChatSession | Eloquent model | ChatSession instance |
 * 
 * ТАБЛИЦІ:
 * ========
 * 
 * New tables (use chat_session_id as FK):
 * - chat_messages.chat_session_id → chat_sessions.id
 * 
 * Legacy analytics tables (use session_id string):
 * - chat_events.session_id → string
 * - chat_conversions.session_id → string  
 * - chat_session_outcomes.session_id → string
 * 
 * Session table:
 * - chat_sessions.id (int PK) 
 * - chat_sessions.session_id (string, unique) ← публічний ідентифікатор
 */
class SessionResolver
{
    /**
     * Get ChatSession by public session_id string
     */
    public static function getBySessionId(string $sessionId): ?ChatSession
    {
        return ChatSession::withoutGlobalScopes()
            ->where('session_id', $sessionId)
            ->first();
    }

    /**
     * Get ChatSession by internal ID (chat_session_id from chat_messages)
     */
    public static function getById(int $chatSessionId): ?ChatSession
    {
        return ChatSession::withoutGlobalScopes()
            ->find($chatSessionId);
    }

    /**
     * Resolve session_id string from internal chat_session_id
     */
    public static function resolveSessionId(int $chatSessionId): ?string
    {
        return ChatSession::withoutGlobalScopes()
            ->where('id', $chatSessionId)
            ->value('session_id');
    }

    /**
     * Resolve internal chat_session_id from public session_id string
     */
    public static function resolveChatSessionId(string $sessionId): ?int
    {
        return ChatSession::withoutGlobalScopes()
            ->where('session_id', $sessionId)
            ->value('id');
    }

    /**
     * Get or create session by public session_id
     */
    public static function getOrCreate(string $sessionId, ?int $tenantId = null): ChatSession
    {
        return ChatSession::withoutGlobalScopes()->firstOrCreate(
            ['session_id' => $sessionId],
            ['tenant_id' => $tenantId]
        );
    }

    /**
     * Check if session exists by public session_id
     */
    public static function exists(string $sessionId): bool
    {
        return ChatSession::withoutGlobalScopes()
            ->where('session_id', $sessionId)
            ->exists();
    }

    /**
     * Get messages for a session (by public session_id)
     * 
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getMessages(string $sessionId)
    {
        $session = self::getBySessionId($sessionId);
        
        if (!$session) {
            return collect();
        }

        return $session->messages()->orderBy('created_at')->get();
    }

    /**
     * Helper: query chat_events by session_id (legacy table)
     */
    public static function queryEvents(string $sessionId)
    {
        return DB::table('chat_events')
            ->where('session_id', $sessionId);
    }

    /**
     * Helper: query chat_conversions by session_id (legacy table)
     */
    public static function queryConversions(string $sessionId)
    {
        return DB::table('chat_conversions')
            ->where('session_id', $sessionId);
    }

    /**
     * Helper: query chat_session_outcomes by session_id (legacy table)
     */
    public static function queryOutcomes(string $sessionId)
    {
        return DB::table('chat_session_outcomes')
            ->where('session_id', $sessionId);
    }
}
