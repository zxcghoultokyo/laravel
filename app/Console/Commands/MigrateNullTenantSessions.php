<?php

namespace App\Console\Commands;

use App\Models\ChatSession;
use Illuminate\Console\Command;

class MigrateNullTenantSessions extends Command
{
    protected $signature = 'chat:migrate-null-tenants {--tenant=2 : Default tenant ID to assign} {--dry-run : Show what would be updated without actually updating}';
    
    protected $description = 'Migrate chat sessions with NULL tenant_id to a default tenant';

    public function handle(): int
    {
        $tenantId = (int) $this->option('tenant');
        $dryRun = $this->option('dry-run');

        $nullSessions = ChatSession::withoutGlobalScopes()
            ->whereNull('tenant_id')
            ->count();

        $this->info("Found {$nullSessions} sessions with NULL tenant_id");

        if ($nullSessions === 0) {
            $this->info('Nothing to migrate!');
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn("DRY RUN: Would update {$nullSessions} sessions to tenant_id={$tenantId}");
            
            // Show sample
            $samples = ChatSession::withoutGlobalScopes()
                ->whereNull('tenant_id')
                ->limit(10)
                ->get(['id', 'session_id', 'created_at']);
                
            $this->table(['ID', 'Session ID', 'Created'], $samples->map(fn($s) => [
                $s->id,
                substr($s->session_id, 0, 30),
                $s->created_at->format('Y-m-d H:i'),
            ]));
            
            return self::SUCCESS;
        }

        if (!$this->confirm("Update {$nullSessions} sessions to tenant_id={$tenantId}?")) {
            $this->info('Cancelled.');
            return self::SUCCESS;
        }

        $updated = ChatSession::withoutGlobalScopes()
            ->whereNull('tenant_id')
            ->update(['tenant_id' => $tenantId]);

        $this->info("✅ Updated {$updated} sessions to tenant_id={$tenantId}");

        return self::SUCCESS;
    }
}
