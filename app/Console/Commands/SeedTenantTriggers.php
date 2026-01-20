<?php

namespace App\Console\Commands;

use App\Models\ProactiveTriggerRule;
use App\Models\Tenant;
use App\Services\Tenant\DefaultTriggerService;
use Illuminate\Console\Command;

class SeedTenantTriggers extends Command
{
    protected $signature = 'triggers:seed {tenant_id? : The tenant ID to seed triggers for} {--force : Delete existing triggers first}';

    protected $description = 'Seed default proactive triggers for a tenant';

    public function handle(DefaultTriggerService $service): int
    {
        $tenantId = $this->argument('tenant_id');
        $force = $this->option('force');

        if ($tenantId) {
            // Seed for specific tenant
            $tenant = Tenant::find($tenantId);
            
            if (!$tenant) {
                $this->error("Tenant ID {$tenantId} not found!");
                return self::FAILURE;
            }

            return $this->seedForTenant($tenant, $service, $force);
        }

        // Seed for all tenants without triggers
        $count = $service->seedMissingTriggers();
        $this->info("✅ Seeded triggers for {$count} tenants");
        
        return self::SUCCESS;
    }

    protected function seedForTenant(Tenant $tenant, DefaultTriggerService $service, bool $force): int
    {
        $existingCount = ProactiveTriggerRule::where('tenant_id', $tenant->id)->count();
        
        if ($existingCount > 0) {
            if ($force) {
                ProactiveTriggerRule::where('tenant_id', $tenant->id)->delete();
                $this->warn("🗑️ Deleted {$existingCount} existing triggers for tenant {$tenant->id}");
            } else {
                $this->warn("Tenant {$tenant->id} ({$tenant->name}) already has {$existingCount} triggers.");
                $this->info("Use --force to delete and recreate.");
                return self::SUCCESS;
            }
        }

        $service->createDefaultTriggers($tenant);
        
        $newCount = ProactiveTriggerRule::where('tenant_id', $tenant->id)->count();
        $this->info("✅ Created {$newCount} triggers for tenant {$tenant->id} ({$tenant->name})");
        
        // List created triggers
        $triggers = ProactiveTriggerRule::where('tenant_id', $tenant->id)
            ->orderBy('priority')
            ->get(['id', 'name', 'trigger_type', 'is_enabled']);
            
        $this->table(
            ['ID', 'Name', 'Type', 'Enabled'],
            $triggers->map(fn($t) => [
                $t->id,
                $t->name,
                $t->trigger_type,
                $t->is_enabled ? '✅' : '❌'
            ])
        );

        return self::SUCCESS;
    }
}
