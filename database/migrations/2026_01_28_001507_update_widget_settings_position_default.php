<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Update default value for new records
        Schema::table('widget_settings', function (Blueprint $table) {
            $table->string('position')->default('bottom-right')->change();
        });
        
        // Update existing records with old 'left' or 'right' values to new format
        DB::table('widget_settings')
            ->where('position', 'left')
            ->update(['position' => 'bottom-left']);
            
        DB::table('widget_settings')
            ->where('position', 'right')
            ->update(['position' => 'bottom-right']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('widget_settings', function (Blueprint $table) {
            $table->string('position')->default('right')->change();
        });
    }
};
