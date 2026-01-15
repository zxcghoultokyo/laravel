<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Models\WidgetSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AssignExistingDataToTenant extends Command
{
    protected $signature = 'tenant:assign-existing {email} {--tenant-id=}';
    protected $description = 'Assign existing unassigned data (products, settings, etc.) to a tenant by user email';

    public function handle(): int
    {
        $email = $this->argument('email');
        
        $user = User::where('email', $email)->first();
        
        if (!$user) {
            $this->error("User with email {$email} not found.");
            return 1;
        }

        $tenantId = $this->option('tenant-id') ?: $user->tenant_id;
        
        if (!$tenantId) {
            // If user is super admin without tenant, create one
            if ($user->isSuperAdmin()) {
                $this->info("Super Admin detected. Creating default tenant...");
                
                $tenant = Tenant::create([
                    'name' => 'Contractor Tactical',
                    'slug' => 'contractor',
                    'email' => $email,
                    'plan' => Tenant::PLAN_PRO,
                    'status' => Tenant::STATUS_ACTIVE,
                    'messages_limit' => Tenant::PLAN_LIMITS[Tenant::PLAN_PRO],
                ]);
                
                $tenantId = $tenant->id;
                
                // Assign tenant to super admin
                $user->update(['tenant_id' => $tenantId]);
                
                $this->info("✅ Created tenant: {$tenant->name} (ID: {$tenantId})");
            } else {
                $this->error("User has no tenant_id and is not a super admin.");
                return 1;
            }
        }

        $tenant = Tenant::find($tenantId);
        if (!$tenant) {
            $this->error("Tenant ID {$tenantId} not found.");
            return 1;
        }

        $this->info("Assigning data to tenant: {$tenant->name} (ID: {$tenantId})");

        // Tables to update
        $tables = [
            'products' => 'Products',
            'product_ai_index' => 'Product AI Index',
            'categories' => 'Categories',
            'category_aliases' => 'Category Aliases',
            'chat_sessions' => 'Chat Sessions',
            'chat_messages' => 'Chat Messages',
            'widget_settings' => 'Widget Settings',
            'greetings' => 'Greetings',
            'prompt_presets' => 'Prompt Presets',
            'orders' => 'Orders',
            'scenarios' => 'Scenarios',
            'store_contexts' => 'Store Contexts',
        ];

        foreach ($tables as $table => $label) {
            if (!DB::getSchemaBuilder()->hasTable($table)) {
                $this->warn("⏭ Table {$table} doesn't exist, skipping.");
                continue;
            }

            if (!DB::getSchemaBuilder()->hasColumn($table, 'tenant_id')) {
                $this->warn("⏭ Table {$table} has no tenant_id column, skipping.");
                continue;
            }

            $count = DB::table($table)
                ->whereNull('tenant_id')
                ->update(['tenant_id' => $tenantId]);

            if ($count > 0) {
                $this->info("✅ {$label}: updated {$count} records");
            } else {
                $this->line("   {$label}: no unassigned records");
            }
        }

        // Check/create widget settings for tenant
        $widgetSettings = WidgetSettings::where('tenant_id', $tenantId)->first();
        if (!$widgetSettings) {
            // Check if there's any widget settings without tenant
            $orphanSettings = WidgetSettings::whereNull('tenant_id')->first();
            if ($orphanSettings) {
                $orphanSettings->update(['tenant_id' => $tenantId]);
                $this->info("✅ Assigned orphan widget settings to tenant");
            } else {
                // Create default
                WidgetSettings::create([
                    'tenant_id' => $tenantId,
                    'domain' => $tenant->slug . '.aimbot.com.ua',
                    'primary_color' => '#2563EB',
                    'welcome_message' => 'Привіт! Чим можу допомогти?',
                    'position' => 'bottom-right',
                ]);
                $this->info("✅ Created new widget settings for tenant");
            }
        }

        $this->newLine();
        $this->info("🎉 Done! All existing data is now assigned to tenant: {$tenant->name}");
        
        // Summary
        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Products', DB::table('products')->where('tenant_id', $tenantId)->count()],
                ['Categories', DB::table('categories')->where('tenant_id', $tenantId)->count()],
                ['Chat Sessions', DB::table('chat_sessions')->where('tenant_id', $tenantId)->count()],
                ['Widget Settings', DB::table('widget_settings')->where('tenant_id', $tenantId)->count()],
            ]
        );

        return 0;
    }
}
