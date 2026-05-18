<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('widget_paused')->default(false)->after('status');
        });

        // Pause widgets for tenant 2 (contractor) and tenant 20 (bavkatoys)
        DB::table('tenants')
            ->whereIn('id', [2, 20])
            ->update(['widget_paused' => true]);
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('widget_paused');
        });
    }
};
