<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('prompt_presets')) {
            return;
        }

        if (Schema::hasColumn('prompt_presets', 'tenant_id')) {
            return;
        }

        Schema::table('prompt_presets', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
            $table->index('tenant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('prompt_presets') || ! Schema::hasColumn('prompt_presets', 'tenant_id')) {
            return;
        }

        Schema::table('prompt_presets', function (Blueprint $table) {
            $table->dropIndex(['tenant_id']);
            $table->dropColumn('tenant_id');
        });
    }
};
