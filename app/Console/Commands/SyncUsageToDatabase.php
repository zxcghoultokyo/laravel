<?php

namespace App\Console\Commands;

use App\Services\Usage\UsageTrackingService;
use Illuminate\Console\Command;

class SyncUsageToDatabase extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'tenants:sync-usage';

    /**
     * The console command description.
     */
    protected $description = 'Sync cached tenant usage counters to database';

    public function __construct(
        protected UsageTrackingService $usageService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Syncing cached usage to database...');
        
        $this->usageService->syncAllToDatabase();
        
        $this->info('✅ Usage synced successfully.');
        
        return Command::SUCCESS;
    }
}
