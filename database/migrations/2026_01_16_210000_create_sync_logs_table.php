<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->string('sync_type', 50)->index(); // horoshop_products, orders, ai_enrichment, meilisearch, categories, embeddings, stats
            $table->string('status', 20)->default('running'); // running, completed, failed
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->integer('duration_seconds')->nullable();
            
            // Stats
            $table->integer('total_processed')->default(0);
            $table->integer('created')->default(0);
            $table->integer('updated')->default(0);
            $table->integer('skipped')->default(0);
            $table->integer('failed')->default(0);
            
            // Additional metrics (JSON)
            $table->json('metrics')->nullable();
            // e.g. { "in_stock": 1234, "out_of_stock": 567, "with_ai": 800, "meili_docs": 1500 }
            
            $table->text('error_message')->nullable();
            $table->text('notes')->nullable();
            
            $table->timestamps();
            
            $table->index(['sync_type', 'started_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
    }
};
