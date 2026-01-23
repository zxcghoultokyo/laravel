<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track onboarding progress for tenants
 * Shows real-time status during sync, AI enrichment, indexing
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_onboarding_progress', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->unique();
            
            // Overall status
            $table->enum('status', ['pending', 'in_progress', 'completed', 'failed'])->default('pending');
            $table->string('current_step')->nullable(); // Current step name
            $table->text('current_step_detail')->nullable(); // Detailed message
            $table->integer('overall_percent')->default(0); // 0-100
            
            // Step-specific progress
            $table->json('steps')->nullable(); // Detailed steps with progress
            
            // Timestamps
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error_message')->nullable();
            
            $table->timestamps();
            
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_onboarding_progress');
    }
};
