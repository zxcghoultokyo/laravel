<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add tenant_id to proactive_trigger_rules
        if (Schema::hasTable('proactive_trigger_rules') && !Schema::hasColumn('proactive_trigger_rules', 'tenant_id')) {
            Schema::table('proactive_trigger_rules', function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
                $table->index('tenant_id');
            });
        }

        // Add tenant_id to proactive_trigger_events
        if (Schema::hasTable('proactive_trigger_events') && !Schema::hasColumn('proactive_trigger_events', 'tenant_id')) {
            Schema::table('proactive_trigger_events', function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
                $table->index('tenant_id');
            });
        }

        // Add tenant_id to sync_logs if missing
        if (Schema::hasTable('sync_logs') && !Schema::hasColumn('sync_logs', 'tenant_id')) {
            Schema::table('sync_logs', function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
                $table->index('tenant_id');
            });
        }

        // Add tenant_id to exports if exists and missing
        if (Schema::hasTable('exports') && !Schema::hasColumn('exports', 'tenant_id')) {
            Schema::table('exports', function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
                $table->index('tenant_id');
            });
        }
    }

    public function down(): void
    {
        $tables = ['proactive_trigger_rules', 'proactive_trigger_events', 'sync_logs', 'exports'];
        
        foreach ($tables as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'tenant_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropIndex(['tenant_id']);
                    $table->dropColumn('tenant_id');
                });
            }
        }
    }
};
