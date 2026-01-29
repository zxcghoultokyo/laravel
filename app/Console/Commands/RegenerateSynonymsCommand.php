<?php

namespace App\Console\Commands;

use App\Models\ProductSynonym;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Regenerate all synonyms - for migration to multi-tenant architecture
 * 
 * Options:
 * 1. --migrate-existing: Convert existing synonyms to global (tenant_id = NULL)
 * 2. --for-all-tenants: Generate tenant-specific synonyms for each tenant
 * 3. --clear-all: Delete ALL synonyms and start fresh
 * 4. --tenant=X: Generate only for specific tenant
 */
class RegenerateSynonymsCommand extends Command
{
    protected $signature = 'synonyms:regenerate 
                            {--migrate-existing : Keep existing synonyms as global (tenant_id = NULL)}
                            {--for-all-tenants : Generate synonyms for each tenant from their products}
                            {--clear-all : Delete ALL existing synonyms first}
                            {--tenant= : Generate only for specific tenant ID}
                            {--dry-run : Show what would happen without making changes}';

    protected $description = 'Regenerate product synonyms for multi-tenant SaaS';

    public function handle(): int
    {
        $this->info('🔄 Synonym Regeneration Tool');
        $this->newLine();

        // Show current state
        $this->showCurrentState();

        if ($this->option('dry-run')) {
            $this->warn('--dry-run mode: No changes will be made');
            $this->newLine();
        }

        // Option 1: Clear everything
        if ($this->option('clear-all')) {
            return $this->clearAndRegenerate();
        }

        // Option 2: Specific tenant
        if ($this->option('tenant')) {
            return $this->regenerateForTenant((int) $this->option('tenant'));
        }

        // Option 3: Migrate existing + generate for all
        if ($this->option('migrate-existing') || $this->option('for-all-tenants')) {
            return $this->migrateAndGenerate();
        }

        // No options - show help
        $this->showHelp();
        return 0;
    }

    protected function showCurrentState(): void
    {
        $total = ProductSynonym::count();
        $withTenant = ProductSynonym::whereNotNull('tenant_id')->count();
        $global = ProductSynonym::whereNull('tenant_id')->count();
        $tenantCount = Tenant::count();
        $tenantsWithSynonyms = ProductSynonym::whereNotNull('tenant_id')
            ->distinct('tenant_id')
            ->count('tenant_id');

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total synonyms', $total],
                ['Global (tenant_id = NULL)', $global],
                ['Tenant-specific', $withTenant],
                ['Total tenants', $tenantCount],
                ['Tenants with synonyms', $tenantsWithSynonyms],
            ]
        );
        $this->newLine();
    }

    protected function clearAndRegenerate(): int
    {
        $this->warn('⚠️  This will DELETE ALL synonyms and regenerate from scratch!');
        
        if (!$this->option('dry-run') && !$this->confirm('Are you sure?')) {
            $this->info('Cancelled.');
            return 0;
        }

        if (!$this->option('dry-run')) {
            $deleted = ProductSynonym::truncate();
            $this->info('✓ Deleted all existing synonyms');
        } else {
            $this->info('[dry-run] Would delete all synonyms');
        }

        // Generate for all tenants
        $tenants = Tenant::all();
        
        foreach ($tenants as $tenant) {
            $this->info("Generating for tenant #{$tenant->id}: {$tenant->name}");
            
            if (!$this->option('dry-run')) {
                Artisan::call('synonyms:products', [
                    '--tenant' => $tenant->id,
                ]);
                $this->line(Artisan::output());
            }
        }

        $this->newLine();
        $this->info('✅ Done! All tenants now have their own synonyms.');
        
        return 0;
    }

    protected function regenerateForTenant(int $tenantId): int
    {
        $tenant = Tenant::find($tenantId);
        
        if (!$tenant) {
            $this->error("Tenant #{$tenantId} not found!");
            return 1;
        }

        $this->info("Regenerating synonyms for tenant #{$tenantId}: {$tenant->name}");

        // Count existing
        $existing = ProductSynonym::where('tenant_id', $tenantId)->count();
        $this->line("Current synonyms for this tenant: {$existing}");

        if (!$this->option('dry-run')) {
            // Delete existing for this tenant
            ProductSynonym::where('tenant_id', $tenantId)->delete();
            $this->info("✓ Deleted {$existing} existing synonyms");

            // Regenerate
            Artisan::call('synonyms:products', [
                '--tenant' => $tenantId,
            ]);
            $this->line(Artisan::output());

            $newCount = ProductSynonym::where('tenant_id', $tenantId)->count();
            $this->info("✓ Generated {$newCount} new synonyms");
        } else {
            $this->info("[dry-run] Would delete {$existing} and regenerate");
        }

        return 0;
    }

    protected function migrateAndGenerate(): int
    {
        // Step 1: Existing synonyms stay as global (they already have tenant_id = NULL)
        if ($this->option('migrate-existing')) {
            $global = ProductSynonym::whereNull('tenant_id')->count();
            $this->info("✓ {$global} existing synonyms are already global (tenant_id = NULL)");
            
            // Make sure any orphaned ones are set to NULL
            if (!$this->option('dry-run')) {
                $orphaned = ProductSynonym::whereNotNull('tenant_id')
                    ->whereNotIn('tenant_id', Tenant::pluck('id'))
                    ->update(['tenant_id' => null]);
                    
                if ($orphaned > 0) {
                    $this->info("✓ Converted {$orphaned} orphaned synonyms to global");
                }
            }
        }

        // Step 2: Generate for each tenant
        if ($this->option('for-all-tenants')) {
            $tenants = Tenant::all();
            
            $this->info("Generating synonyms for {$tenants->count()} tenants...");
            $this->newLine();

            $bar = $this->output->createProgressBar($tenants->count());
            $bar->start();

            foreach ($tenants as $tenant) {
                if (!$this->option('dry-run')) {
                    // Check if tenant already has synonyms
                    $existing = ProductSynonym::where('tenant_id', $tenant->id)->count();
                    
                    if ($existing === 0) {
                        Artisan::call('synonyms:products', [
                            '--tenant' => $tenant->id,
                        ]);
                    }
                }
                $bar->advance();
            }

            $bar->finish();
            $this->newLine(2);
        }

        $this->showCurrentState();
        $this->info('✅ Migration complete!');
        
        return 0;
    }

    protected function showHelp(): void
    {
        $this->info('Usage options:');
        $this->newLine();
        
        $this->line('  <fg=yellow>1. Keep existing as global + generate for all tenants:</>');
        $this->line('     php artisan synonyms:regenerate --migrate-existing --for-all-tenants');
        $this->newLine();
        
        $this->line('  <fg=yellow>2. Clear everything and regenerate per-tenant:</>');
        $this->line('     php artisan synonyms:regenerate --clear-all');
        $this->newLine();
        
        $this->line('  <fg=yellow>3. Regenerate for specific tenant:</>');
        $this->line('     php artisan synonyms:regenerate --tenant=9');
        $this->newLine();
        
        $this->line('  <fg=yellow>4. Preview without changes:</>');
        $this->line('     php artisan synonyms:regenerate --clear-all --dry-run');
        $this->newLine();
        
        $this->info('💡 Recommendation for existing DB:');
        $this->line('   php artisan synonyms:regenerate --migrate-existing --for-all-tenants');
        $this->line('   This keeps old synonyms as global fallback and adds tenant-specific ones.');
    }
}
