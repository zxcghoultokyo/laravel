<?php

namespace App\Console\Commands;

use App\Services\Catalog\CategoryIndexService;
use Illuminate\Console\Command;

class RebuildCategoriesCommand extends Command
{
    protected $signature = 'categories:rebuild 
        {--tenant= : Rebuild for specific tenant ID only}';

    protected $description = 'Rebuild categories from products (per tenant)';

    public function handle(CategoryIndexService $service): int
    {
        $tenantId = $this->option('tenant');
        
        if ($tenantId) {
            $this->info("Rebuilding categories for tenant {$tenantId}...");
            $service->rebuildForTenant((int) $tenantId);
        } else {
            $this->info('Rebuilding categories for ALL tenants...');
            $service->rebuild();
        }
        
        $this->info('Done!');
        
        return Command::SUCCESS;
    }
}
