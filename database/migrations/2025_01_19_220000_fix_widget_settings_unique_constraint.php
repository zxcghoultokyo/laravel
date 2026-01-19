<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('widget_settings', function (Blueprint $table) {
            // Drop old unique index on domain only
            $table->dropUnique(['domain']);
        });
        
        Schema::table('widget_settings', function (Blueprint $table) {
            // Add composite unique index on domain + tenant_id
            $table->unique(['domain', 'tenant_id'], 'widget_settings_domain_tenant_unique');
        });
    }

    public function down(): void
    {
        Schema::table('widget_settings', function (Blueprint $table) {
            $table->dropUnique('widget_settings_domain_tenant_unique');
        });
        
        Schema::table('widget_settings', function (Blueprint $table) {
            $table->unique('domain');
        });
    }
};
