<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ab_test_events', function (Blueprint $table) {
            $table->id();
            $table->string('experiment', 50)->index();
            $table->string('variant', 20)->index();
            $table->string('session_id', 100)->index();
            $table->string('event', 50)->index();
            $table->json('data')->nullable();
            $table->timestamp('created_at')->index();
            
            // Composite index for efficient queries
            $table->index(['experiment', 'variant', 'event']);
            $table->index(['experiment', 'variant', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ab_test_events');
    }
};
