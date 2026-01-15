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
        // Mark tenant ID 1 (Contractor) as onboarding completed
        DB::table('tenants')
            ->where('id', 1)
            ->update([
                'settings' => json_encode(['onboarding_completed' => true])
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('tenants')
            ->where('id', 1)
            ->update([
                'settings' => null
            ]);
    }
};
