<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('greetings', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100); // Internal name
            $table->text('message'); // Greeting text with emoji
            $table->json('quick_actions')->nullable(); // [{text: "...", action: "..."}]
            
            // Conditions
            $table->string('utm_campaign')->nullable();
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('url_contains')->nullable(); // Regex or simple contains
            $table->string('category_path')->nullable(); // Category path match
            $table->enum('device', ['any', 'mobile', 'desktop'])->default('any');
            $table->enum('visitor_type', ['any', 'new', 'returning'])->default('any');
            $table->string('language')->nullable(); // Browser language (uk, en, ru)
            $table->json('time_range')->nullable(); // {start: "09:00", end: "18:00"}
            
            // Settings
            $table->integer('priority')->default(0); // Higher = checked first
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false); // Fallback greeting
            
            $table->timestamps();
            
            $table->index(['is_active', 'priority']);
            $table->index('is_default');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('greetings');
    }
};
