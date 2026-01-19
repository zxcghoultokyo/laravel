<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // First, add the column if it doesn't exist
        if (!Schema::hasColumn('users', 'is_superadmin')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('is_superadmin')->default(false)->after('email');
            });
        }
        
        // Then set is_superadmin = true for specific user
        DB::table('users')
            ->where('email', 'stovburtm@gmail.com')
            ->update(['is_superadmin' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('users')
            ->where('email', 'stovburtm@gmail.com')
            ->update(['is_superadmin' => false]);
    }
};
