<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('prompt_presets', 'store_type')) {
            Schema::table('prompt_presets', function (Blueprint $table) {
                $table->string('store_type')->nullable()->after('campaign');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('prompt_presets', 'store_type')) {
            Schema::table('prompt_presets', function (Blueprint $table) {
                $table->dropColumn('store_type');
            });
        }
    }
};
