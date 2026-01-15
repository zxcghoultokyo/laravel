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
        Schema::create('canned_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')
                  ->constrained()
                  ->onDelete('cascade');
            $table->string('title');
            $table->text('content');
            $table->string('shortcut', 50)->nullable()->index();
            $table->string('category', 50)->nullable()->index();
            $table->unsignedInteger('usage_count')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->json('variables')->nullable();
            $table->timestamps();

            // Composite indexes
            $table->index(['tenant_id', 'is_active']);
            $table->index(['tenant_id', 'category']);
            $table->unique(['tenant_id', 'shortcut']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('canned_responses');
    }
};
