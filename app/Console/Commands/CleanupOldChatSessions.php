<?php

namespace App\Console\Commands;

use App\Models\ChatSession;
use App\Models\ChatMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupOldChatSessions extends Command
{
    protected $signature = 'chat:cleanup-old 
                            {--days=90 : Delete sessions older than N days}
                            {--dry-run : Show count without deleting}';

    protected $description = 'Delete old chat sessions and their messages (default: 90 days)';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $dryRun = $this->option('dry-run');
        $cutoff = now()->subDays($days);

        $sessionsQuery = ChatSession::where('created_at', '<', $cutoff);
        $sessionCount = $sessionsQuery->count();

        if ($sessionCount === 0) {
            $this->info("No chat sessions older than {$days} days.");
            return 0;
        }

        $sessionIds = $sessionsQuery->pluck('id')->toArray();
        $messageCount = ChatMessage::whereIn('chat_session_id', $sessionIds)->count();

        $this->info("Found {$sessionCount} sessions with {$messageCount} messages older than {$days} days (before {$cutoff->toDateString()}).");

        if ($dryRun) {
            $this->warn('Dry run — no changes made.');
            return 0;
        }

        if (!$this->confirm("Delete {$sessionCount} sessions and {$messageCount} messages?")) {
            return 0;
        }

        // Delete in chunks to avoid memory issues
        $deletedMessages = 0;
        $deletedSessions = 0;

        foreach (array_chunk($sessionIds, 500) as $chunk) {
            $deletedMessages += ChatMessage::whereIn('chat_session_id', $chunk)->delete();
            $deletedSessions += ChatSession::whereIn('id', $chunk)->delete();
        }

        $this->info("Deleted {$deletedSessions} sessions and {$deletedMessages} messages.");

        Log::info('chat:cleanup-old completed', [
            'days' => $days,
            'deleted_sessions' => $deletedSessions,
            'deleted_messages' => $deletedMessages,
        ]);

        return 0;
    }
}
