<?php

namespace App\Console\Commands;

use App\Services\Usage\UsageTrackingService;
use Illuminate\Console\Command;

class ResetMonthlyUsage extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'tenants:reset-usage 
                            {--tenant= : Reset specific tenant by ID}
                            {--sync : Sync cached usage to database before reset}';

    /**
     * The console command description.
     */
    protected $description = 'Reset monthly message usage for all tenants (run on 1st of each month)';

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
        // Sync cached values to DB first
        if ($this->option('sync')) {
            $this->info('Syncing cached usage to database...');
            $this->usageService->syncAllToDatabase();
        }

        // Reset specific tenant
        if ($tenantId = $this->option('tenant')) {
            $tenant = \App\Models\Tenant::find($tenantId);
            
            if (!$tenant) {
                $this->error("Tenant #{$tenantId} not found.");
                return Command::FAILURE;
            }
            
            $this->usageService->resetMonthlyUsage($tenant);
            $this->info("✅ Reset usage for tenant: {$tenant->name}");
            
            return Command::SUCCESS;
        }

        // Reset all tenants
        $this->info('Resetting monthly usage for all tenants...');
        $count = $this->usageService->resetAllMonthlyUsage();
        
        $this->info("✅ Reset usage for {$count} tenants.");
        
        return Command::SUCCESS;
    }
}
