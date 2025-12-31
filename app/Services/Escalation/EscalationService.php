<?php

namespace App\Services\Escalation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EscalationService
{
    /**
     * Check if session needs human assistance based on AI response.
     * AI should detect situations like:
     * - Complex B2B requests (bulk orders, custom branding)
     * - Price negotiations ("дорого", "знижка")
     * - Technical questions AI can't answer confidently
     * - Customer frustration/repeated questions
     */
    public function checkAndEscalate(string $sessionId, array $response, string $query): bool
    {
        // Check if AI flagged this for escalation
        $needsHuman = $response['meta']['needs_human'] ?? false;
        $escalationReason = $response['meta']['escalation_reason'] ?? null;

        // Also check for fallback intent which may indicate AI confusion
        $intent = $response['meta']['intent'] ?? null;
        if ($intent === 'FALLBACK' && !$needsHuman) {
            // If AI returned fallback, might need human
            $needsHuman = true;
            $escalationReason = $escalationReason ?? 'AI не змогла класифікувати запит';
        }

        if (!$needsHuman) {
            return false;
        }

        // Mark session as needing human help
        $this->markSessionForEscalation($sessionId, $escalationReason, $query);

        return true;
    }

    /**
     * Mark a session as needing human assistance.
     */
    public function markSessionForEscalation(string $sessionId, ?string $reason, ?string $lastQuery = null): void
    {
        try {
            DB::table('active_chat_sessions')->updateOrInsert(
                ['session_id' => $sessionId],
                [
                    'needs_human' => true,
                    'escalation_reason' => $reason,
                    'escalated_at' => now(),
                    'last_message_at' => now(),
                    'last_query' => $lastQuery ? mb_substr($lastQuery, 0, 255) : null,
                    'updated_at' => now(),
                ]
            );

            Log::info('EscalationService: session marked for escalation', [
                'session_id' => $sessionId,
                'reason' => $reason,
            ]);

            // Send notification if not already sent
            $this->sendNotificationIfNeeded($sessionId, $reason);

        } catch (\Throwable $e) {
            Log::error('EscalationService: failed to mark session', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
            ]);
        }
    }

    /**
     * Send email notification about escalation.
     */
    protected function sendNotificationIfNeeded(string $sessionId, ?string $reason): void
    {
        try {
            // Check if notification already sent
            $session = DB::table('active_chat_sessions')
                ->where('session_id', $sessionId)
                ->first();

            if ($session && $session->notification_sent) {
                return;
            }

            // Get notification email from env
            $notifyEmail = config('services.escalation.notify_email');
            
            if (empty($notifyEmail)) {
                Log::debug('EscalationService: no notify email configured');
                return;
            }

            // Send simple email
            $adminUrl = config('app.url') . '/admin/chats/' . $sessionId;
            
            Mail::raw(
                "Новий запит потребує допомоги оператора!\n\n" .
                "Сесія: {$sessionId}\n" .
                "Причина: {$reason}\n\n" .
                "Переглянути: {$adminUrl}",
                function ($message) use ($notifyEmail, $sessionId) {
                    $message->to($notifyEmail)
                        ->subject("🚨 Ескалація чату: {$sessionId}");
                }
            );

            // Mark as notified
            DB::table('active_chat_sessions')
                ->where('session_id', $sessionId)
                ->update(['notification_sent' => true]);

            Log::info('EscalationService: notification sent', [
                'session_id' => $sessionId,
                'email' => $notifyEmail,
            ]);

        } catch (\Throwable $e) {
            Log::error('EscalationService: failed to send notification', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
            ]);
        }
    }

    /**
     * Get all sessions needing human assistance.
     */
    public function getEscalatedSessions(int $limit = 50): array
    {
        return DB::table('active_chat_sessions')
            ->where('needs_human', true)
            ->where('status', '!=', 'operator') // Not yet taken by operator
            ->orderByDesc('escalated_at')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * Clear escalation flag (when operator takes over or AI resolves).
     */
    public function clearEscalation(string $sessionId): void
    {
        DB::table('active_chat_sessions')
            ->where('session_id', $sessionId)
            ->update([
                'needs_human' => false,
                'escalation_reason' => null,
                'escalated_at' => null,
                'notification_sent' => false,
                'updated_at' => now(),
            ]);
    }

    /**
     * Count escalated sessions.
     */
    public function countEscalated(): int
    {
        return DB::table('active_chat_sessions')
            ->where('needs_human', true)
            ->where('status', '!=', 'operator')
            ->count();
    }
}
