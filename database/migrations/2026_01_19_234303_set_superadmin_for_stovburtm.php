<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Set is_superadmin = true for specific user
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
