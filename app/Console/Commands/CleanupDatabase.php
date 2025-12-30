<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CleanupDatabase extends Command
{
    protected $signature = 'db:cleanup 
        {--days=30 : Keep data newer than N days}
        {--dry-run : Show what would be deleted without actually deleting}
        {--force : Skip confirmation}';

    protected $description = 'Clean up old data from database to free up space';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $dryRun = $this->option('dry-run');
        $cutoff = now()->subDays($days);

        $this->info("Cleaning up data older than {$days} days (before {$cutoff})");
        
        if ($dryRun) {
            $this->warn('DRY RUN - no data will be deleted');
        }

        $tables = [
            'chat_messages' => 'created_at',
            'chat_sessions' => 'updated_at',
            'chat_metrics' => 'created_at',
            'ai_generation_logs' => 'created_at',
            'jobs' => 'created_at',
            'failed_jobs' => 'failed_at',
        ];

        $totalDeleted = 0;

        foreach ($tables as $table => $dateColumn) {
            if (!Schema::hasTable($table)) {
                $this->line("  ⏭ Table {$table} does not exist, skipping");
                continue;
            }

            $count = DB::table($table)
                ->where($dateColumn, '<', $cutoff)
                ->count();

            if ($count === 0) {
                $this->line("  ✓ {$table}: nothing to delete");
                continue;
            }

            if (!$dryRun) {
                // Delete in chunks to avoid locking
                $deleted = 0;
                do {
                    $chunk = DB::table($table)
                        ->where($dateColumn, '<', $cutoff)
                        ->limit(1000)
                        ->delete();
                    $deleted += $chunk;
                } while ($chunk > 0);

                $this->info("  🗑 {$table}: deleted {$deleted} rows");
                $totalDeleted += $deleted;
            } else {
                $this->warn("  📊 {$table}: would delete {$count} rows");
                $totalDeleted += $count;
            }
        }

        // Clean up cache table if using database cache
        if (Schema::hasTable('cache')) {
            $expiredCount = DB::table('cache')
                ->where('expiration', '<', now()->timestamp)
                ->count();
            
            if ($expiredCount > 0) {
                if (!$dryRun) {
                    DB::table('cache')->where('expiration', '<', now()->timestamp)->delete();
                    $this->info("  🗑 cache: deleted {$expiredCount} expired entries");
                } else {
                    $this->warn("  📊 cache: would delete {$expiredCount} expired entries");
                }
            }
        }

        $this->newLine();
        
        if ($dryRun) {
            $this->info("DRY RUN complete. Would delete ~{$totalDeleted} rows total.");
            $this->info("Run without --dry-run to actually delete.");
        } else {
            $this->info("✅ Cleanup complete! Deleted {$totalDeleted} rows.");
        }

        // Show current disk usage hint
        $this->newLine();
        $this->comment("💡 Tip: After cleanup, run OPTIMIZE TABLE on large tables:");
        $this->line("   php artisan tinker --execute=\"DB::statement('OPTIMIZE TABLE products, chat_messages, chat_sessions');\"");

        return self::SUCCESS;
    }
}
