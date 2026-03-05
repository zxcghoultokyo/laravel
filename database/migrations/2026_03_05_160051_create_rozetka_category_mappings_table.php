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
        Schema::create('rozetka_category_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('local_category_id')->nullable();
            $table->string('local_category_name');
            $table->string('local_category_source')->default('hprofit');
            $table->unsignedBigInteger('rozetka_category_id');
            $table->string('rozetka_category_name');
            $table->string('rozetka_category_path')->nullable();
            $table->boolean('is_confirmed')->default(false);
            $table->string('matched_by')->default('manual');
            $table->timestamps();

            $table->unique(['tenant_id', 'local_category_id', 'local_category_source'], 'mapping_unique');
            $table->index('rozetka_category_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rozetka_category_mappings');
    }
};
