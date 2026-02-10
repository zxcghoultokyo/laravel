<?php

namespace App\Console\Commands;

use App\Models\ChatSession;
use App\Models\ChatMessage;
use Illuminate\Console\Command;

class CleanupTestSessions extends Command
{
    protected $signature = 'chat:cleanup-test-sessions 
                            {--keep= : Session ID to keep (not delete)}
                            {--dry-run : Show sessions without deleting}';

    protected $description = 'Delete test chat sessions (test_*, diagnostic_*, debug_*)';

    public function handle(): int
    {
        $keepSession = $this->option('keep');
        $dryRun = $this->option('dry-run');

        $query = ChatSession::where(function($q) {
            $q->where('session_id', 'like', 'test_%')
              ->orWhere('session_id', 'like', 'diagnostic_%')
              ->orWhere('session_id', 'like', 'debug_%');
        });

        if ($keepSession) {
            $query->where('session_id', '!=', $keepSession);
        }

        $testSessions = $query->get();

        $this->info("Found {$testSessions->count()} test sessions");

        if ($testSessions->isEmpty()) {
            $this->info('No test sessions to delete');
            return 0;
        }

        $this->table(
            ['ID', 'Session ID', 'Tenant', 'Messages', 'Created'],
            $testSessions->map(fn($s) => [
                $s->id,
                substr($s->session_id, 0, 40),
                $s->tenant_id,
                $s->messages()->count(),
                $s->created_at?->format('Y-m-d H:i'),
            ])
        );

        if ($dryRun) {
            $this->warn('Dry run - no changes made');
            return 0;
        }

        if (!$this->confirm('Delete these sessions?')) {
            return 0;
        }

        $ids = $testSessions->pluck('id')->toArray();
        
        $deletedMessages = ChatMessage::whereIn('chat_session_id', $ids)->delete();
        $this->info("Deleted {$deletedMessages} messages");

        $deletedSessions = ChatSession::whereIn('id', $ids)->delete();
        $this->info("Deleted {$deletedSessions} sessions");

        return 0;
    }
}
