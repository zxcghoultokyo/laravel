<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tables that need tenant_id.
     */
    private array $tables = [
        'products',
        'product_ai_index',
        'categories',
        'category_aliases',
        'chat_sessions',
        'chat_messages',
        'widget_settings',
        'greetings',
        'prompt_presets',
        'orders',
        'scenarios',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }
            
            if (Schema::hasColumn($tableName, 'tenant_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                // Use simple column without foreign key for SQLite compatibility
                $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
                $table->index('tenant_id');
            });
        }

        // Ensure composite unique on widget_settings (domain + tenant_id)
        // This may have been skipped by the earlier migration if tenant_id didn't exist yet
        if (Schema::hasTable('widget_settings')
            && Schema::hasColumn('widget_settings', 'tenant_id')
            && ! Schema::hasIndex('widget_settings', 'widget_settings_domain_tenant_unique')) {
            Schema::table('widget_settings', function (Blueprint $table) {
                $table->unique(['domain', 'tenant_id'], 'widget_settings_domain_tenant_unique');
            });
        }
        
        // Create store_contexts table if it doesn't exist (with tenant support)
        if (!Schema::hasTable('store_contexts')) {
            Schema::create('store_contexts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable();
                $table->unsignedBigInteger('widget_settings_id')->nullable();
                $table->string('store_type', 50)->nullable();
                $table->json('primary_categories')->nullable();
                $table->json('brands')->nullable();
                $table->json('price_segments')->nullable();
                $table->json('expertise_areas')->nullable();
                $table->text('generated_prompt')->nullable();
                $table->timestamp('analyzed_at')->nullable();
                $table->timestamps();
                
                $table->index('tenant_id');
                $table->index('store_type');
            });
        } else {
            // Add tenant_id to existing store_contexts
            if (!Schema::hasColumn('store_contexts', 'tenant_id')) {
                Schema::table('store_contexts', function (Blueprint $table) {
                    $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
                    $table->index('tenant_id');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop store_contexts first
        Schema::dropIfExists('store_contexts');
        
        // Remove tenant_id from all tables
        foreach ($this->tables as $tableName) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }
            
            if (!Schema::hasColumn($tableName, 'tenant_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                // Only drop foreign key if it exists (was never created with FK)
                try {
                    $table->dropForeign(['tenant_id']);
                } catch (\Throwable $e) {
                    // FK doesn't exist, ignore
                }

                if (Schema::hasIndex($tableName, $tableName . '_tenant_id_index')) {
                    $table->dropIndex([$tableName . '_tenant_id_index']);
                } elseif (Schema::hasIndex($tableName, 'tenant_id')) {
                    $table->dropIndex(['tenant_id']);
                }

                $table->dropColumn('tenant_id');
            });
        }
    }
};
