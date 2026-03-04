<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Drop old unique index on domain only (if it exists on production DB)
        if (Schema::hasIndex('widget_settings', 'widget_settings_domain_unique')) {
            Schema::table('widget_settings', function (Blueprint $table) {
                $table->dropUnique('widget_settings_domain_unique');
            });
        }

        // Add composite unique index on domain + tenant_id (if not already present)
        // Note: tenant_id column is added in a later migration (2025_01_20_000006)
        // On fresh DB, skip this — the later migration will handle it
        if (Schema::hasColumn('widget_settings', 'tenant_id')
            && ! Schema::hasIndex('widget_settings', 'widget_settings_domain_tenant_unique')) {
            Schema::table('widget_settings', function (Blueprint $table) {
                $table->unique(['domain', 'tenant_id'], 'widget_settings_domain_tenant_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('widget_settings', 'widget_settings_domain_tenant_unique')) {
            Schema::table('widget_settings', function (Blueprint $table) {
                $table->dropUnique('widget_settings_domain_tenant_unique');
            });
        }

        if (! Schema::hasIndex('widget_settings', 'widget_settings_domain_unique')) {
            Schema::table('widget_settings', function (Blueprint $table) {
                $table->unique('domain');
            });
        }
    }
};
