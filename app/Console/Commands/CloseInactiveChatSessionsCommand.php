<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CloseInactiveChatSessionsCommand extends Command
{
    protected $signature = 'chat:close-inactive 
                            {--timeout=30 : Minutes of inactivity before closing}
                            {--dry-run : Show what would be closed without making changes}';

    protected $description = 'Close chat sessions that have been inactive for specified time';

    public function handle(): int
    {
        $timeoutMinutes = (int) $this->option('timeout');
        $dryRun = $this->option('dry-run');
        $cutoffTime = Carbon::now()->subMinutes($timeoutMinutes);

        $this->info("Closing sessions inactive since: {$cutoffTime->toDateTimeString()}");
        $this->info("Timeout: {$timeoutMinutes} minutes");

        // Find open sessions with no recent activity
        // Use updated_at as the activity indicator (it updates when messages are added)
        $query = DB::table('chat_sessions')
            ->where('status', 'open')
            ->where('updated_at', '<', $cutoffTime);

        $sessionsToClose = $query->get();
        $count = $sessionsToClose->count();

        if ($count === 0) {
            $this->info('No inactive sessions to close.');
            return Command::SUCCESS;
        }

        $this->info("Found {$count} inactive sessions");

        if ($dryRun) {
            $this->warn('DRY RUN - No changes will be made');
            $this->table(
                ['ID', 'Session ID', 'Tenant', 'Messages', 'Last Activity'],
                $sessionsToClose->map(fn($s) => [
                    $s->id,
                    substr($s->session_id, 0, 35),
                    $s->tenant_id,
                    $s->messages_count,
                    $s->updated_at,
                ])->toArray()
            );
            return Command::SUCCESS;
        }

        // Close the sessions
        $updated = DB::table('chat_sessions')
            ->where('status', 'open')
            ->where('updated_at', '<', $cutoffTime)
            ->update([
                'status' => 'closed',
                'updated_at' => Carbon::now(), // Mark when we closed it
            ]);

        $this->info("✓ Closed {$updated} sessions");

        return Command::SUCCESS;
    }
}
