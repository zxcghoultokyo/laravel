<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add tenant_id to chat_events and migrate existing data
     */
    public function up(): void
    {
        // 1. Add tenant_id column if not exists
        if (!Schema::hasColumn('chat_events', 'tenant_id')) {
            Schema::table('chat_events', function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable()->after('merchant_id');
                $table->index('tenant_id');
            });
        }
        
        // 2. Migrate existing data: map merchant_id (api_token or slug) to tenant_id
        // Get all widget settings with their api_tokens
        $widgetSettings = DB::table('widget_settings')
            ->select('tenant_id', 'api_token')
            ->whereNotNull('api_token')
            ->get();
        
        // Get all tenants with their slugs
        $tenants = DB::table('tenants')
            ->select('id', 'slug')
            ->get();
        
        // Build mapping: merchant_id => tenant_id
        $merchantToTenant = [];
        
        // Map api_tokens to tenant_id
        foreach ($widgetSettings as $ws) {
            if ($ws->api_token && $ws->tenant_id) {
                $merchantToTenant[$ws->api_token] = $ws->tenant_id;
            }
        }
        
        // Map slugs to tenant_id
        foreach ($tenants as $tenant) {
            if ($tenant->slug) {
                $merchantToTenant[$tenant->slug] = $tenant->id;
            }
        }
        
        // Update chat_events with tenant_id based on merchant_id
        foreach ($merchantToTenant as $merchantId => $tenantId) {
            DB::table('chat_events')
                ->where('merchant_id', $merchantId)
                ->whereNull('tenant_id')
                ->update(['tenant_id' => $tenantId]);
        }
        
        // 3. Also update merchant_id for old records that used api_token
        // Convert hash-based merchant_id to slug for consistency
        foreach ($widgetSettings as $ws) {
            if ($ws->api_token && $ws->tenant_id) {
                $tenant = $tenants->firstWhere('id', $ws->tenant_id);
                if ($tenant && $tenant->slug) {
                    // Only update if merchant_id looks like a hash (64 chars hex)
                    if (preg_match('/^[a-f0-9]{64}$/', $ws->api_token)) {
                        DB::table('chat_events')
                            ->where('merchant_id', $ws->api_token)
                            ->update(['merchant_id' => $tenant->slug]);
                    }
                }
            }
        }
        
        // Log migration results
        $updatedCount = DB::table('chat_events')->whereNotNull('tenant_id')->count();
        $totalCount = DB::table('chat_events')->count();
        \Log::info("Migration: Added tenant_id to chat_events", [
            'updated' => $updatedCount,
            'total' => $totalCount
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('chat_events', 'tenant_id')) {
            Schema::table('chat_events', function (Blueprint $table) {
                $table->dropIndex(['tenant_id']);
                $table->dropColumn('tenant_id');
            });
        }
    }
};
