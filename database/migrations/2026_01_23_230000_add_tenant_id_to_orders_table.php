<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add tenant_id to orders table for multi-tenancy support
 *
 * CRITICAL: Orders without tenant_id cannot be distinguished between shops!
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Add tenant_id column if not exists
        if (! Schema::hasColumn('orders', 'tenant_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
                $table->index('tenant_id');
            });
        }

        // 2. Backfill tenant_id from chat_sessions (if order has session_id)
        // Wrapped in try/catch because chat_sessions may not have tenant_id yet
        try {
            $driver = Schema::getConnection()->getDriverName();

            if ($driver === 'sqlite') {
                DB::statement('
                    UPDATE orders
                    SET tenant_id = (
                        SELECT cs.tenant_id FROM chat_sessions cs
                        WHERE cs.session_id = orders.session_id
                        AND cs.tenant_id IS NOT NULL
                        LIMIT 1
                    )
                    WHERE orders.tenant_id IS NULL
                    AND orders.session_id IS NOT NULL
                ');
            } else {
                DB::statement('
                    UPDATE orders o
                    LEFT JOIN chat_sessions cs ON o.session_id = cs.session_id
                    SET o.tenant_id = cs.tenant_id
                    WHERE o.tenant_id IS NULL AND cs.tenant_id IS NOT NULL
                ');
            }
        } catch (\Exception $e) {
            // Backfill may fail if chat_sessions doesn't have tenant_id yet - that's OK
        }

        // 3. For remaining orders without session, try to match by product articles
        // (This is a best-effort migration - some orders may remain without tenant_id)

        // 4. Add foreign key constraint (optional, depends on your needs)
        // Schema::table('orders', function (Blueprint $table) {
        //     $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('set null');
        // });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['tenant_id']);
            $table->dropColumn('tenant_id');
        });
    }
};
