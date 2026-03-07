<?php

namespace App\Console\Commands;

use App\Jobs\FetchHoroshopOrdersJob;
use App\Models\Tenant;
use Illuminate\Console\Command;

class SyncHoroshopOrders extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'horoshop:sync-orders 
                            {--hours=24 : Fetch orders from last N hours}
                            {--from= : From date (YYYY-MM-DD)}
                            {--to= : To date (YYYY-MM-DD)}
                            {--sync : Run synchronously instead of queuing}
                            {--tenant-id= : Sync orders for a specific tenant}
                            {--all-tenants : Sync orders for all active tenants with Horoshop credentials}';

    /**
     * The console command description.
     */
    protected $description = 'Sync orders from Horoshop API and link to chat sessions';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Multi-tenant: loop over all active tenants
        if ($this->option('all-tenants')) {
            return $this->handleAllTenants();
        }

        $hours = (int) $this->option('hours');
        $fromDate = $this->option('from');
        $toDate = $this->option('to');
        $sync = $this->option('sync');
        $tenantId = $this->option('tenant-id') ? (int) $this->option('tenant-id') : null;

        if ($tenantId) {
            $tenant = Tenant::find($tenantId);
            if (! $tenant) {
                $this->error("Tenant #{$tenantId} not found.");

                return Command::FAILURE;
            }
            $this->info("Syncing for tenant #{$tenantId} ({$tenant->name})");
        }

        if (! $fromDate) {
            $fromDate = now()->subHours($hours)->format('Y-m-d H:i:s');
        }
        if (! $toDate) {
            $toDate = now()->format('Y-m-d H:i:s');
        }

        $this->info('Syncing orders from Horoshop...');
        $this->info("From: {$fromDate}");
        $this->info("To: {$toDate}");

        $job = new FetchHoroshopOrdersJob(
            sessionId: null,
            fromDate: $fromDate,
            toDate: $toDate,
            orderIds: null,
            linkToChat: true,
            tenantId: $tenantId
        );

        if ($sync) {
            $this->info('Running synchronously...');
            dispatch_sync($job);
        } else {
            $this->info('Dispatching to queue...');
            dispatch($job);
        }

        $this->info('✅ Done! Check logs for details.');

        return Command::SUCCESS;
    }

    /**
     * Sync orders for all active tenants with Horoshop credentials.
     */
    protected function handleAllTenants(): int
    {
        $tenants = Tenant::where('status', '!=', Tenant::STATUS_CANCELLED)
            ->where('platform', 'horoshop')
            ->whereNotNull('platform_credentials')
            ->get();

        if ($tenants->isEmpty()) {
            $this->info('No active tenants with Horoshop credentials found.');

            return Command::SUCCESS;
        }

        $this->info("Syncing orders for {$tenants->count()} tenants...");

        foreach ($tenants as $tenant) {
            $this->info("  Tenant #{$tenant->id} ({$tenant->name})...");
            $this->call('horoshop:sync-orders', [
                '--tenant-id' => $tenant->id,
                '--hours' => $this->option('hours'),
                '--sync' => $this->option('sync'),
            ]);
        }

        $this->info('✅ All tenants synced!');

        return Command::SUCCESS;
    }
}
