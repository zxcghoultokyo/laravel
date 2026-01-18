<?php

namespace App\Console\Commands;

use App\Services\Tenant\DefaultTriggerService;
use Illuminate\Console\Command;

class SeedDefaultTriggers extends Command
{
    protected $signature = 'tenants:seed-triggers 
                           {--tenant= : Specific tenant ID or slug to seed}
                           {--force : Force seed even if tenant already has triggers}';

    protected $description = 'Seed default proactive triggers for tenants that don\'t have any';

    public function handle(DefaultTriggerService $service): int
    {
        $tenantOption = $this->option('tenant');
        $force = $this->option('force');

        if ($tenantOption) {
            // Seed specific tenant
            $tenant = \App\Models\Tenant::where('id', $tenantOption)
                ->orWhere('slug', $tenantOption)
                ->first();

            if (!$tenant) {
                $this->error("Tenant not found: {$tenantOption}");
                return Command::FAILURE;
            }

            if (!$force && $service->hasTriggers($tenant)) {
                $this->warn("Tenant '{$tenant->name}' already has triggers. Use --force to override.");
                return Command::SUCCESS;
            }

            if ($force) {
                // Delete existing triggers first
                $tenant->proactiveTriggerRules()->delete();
                $this->info("Deleted existing triggers for '{$tenant->name}'");
            }

            $service->createDefaultTriggers($tenant);
            $this->info("✓ Created default triggers for '{$tenant->name}'");

            return Command::SUCCESS;
        }

        // Seed all tenants without triggers
        $count = $service->seedMissingTriggers();

        if ($count === 0) {
            $this->info('All tenants already have triggers. Nothing to do.');
        } else {
            $this->info("✓ Created default triggers for {$count} tenant(s)");
        }

        return Command::SUCCESS;
    }
}
