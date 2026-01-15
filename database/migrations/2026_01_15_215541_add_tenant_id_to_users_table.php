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
        Schema::table('users', function (Blueprint $table) {
            // Add tenant relationship (nullable for backwards compatibility)
            $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
            
            // Role within tenant
            $table->string('role', 20)->default('member')->after('email');
            // owner, admin, member
            
            // Index for tenant scoping
            $table->index('tenant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['tenant_id']);
            $table->dropColumn(['tenant_id', 'role']);
        });
    }
};
