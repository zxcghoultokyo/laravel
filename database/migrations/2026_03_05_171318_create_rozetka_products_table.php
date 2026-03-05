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
        Schema::create('rozetka_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('rozetka_item_id')->unique()->index();
            $table->string('article')->index();
            $table->string('parent_article')->nullable()->index();
            $table->string('title');
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('price_old', 12, 2)->nullable();
            $table->unsignedBigInteger('rozetka_category_id')->nullable()->index();
            $table->string('rozetka_category_name')->nullable();
            $table->boolean('in_stock')->default(false);
            $table->unsignedSmallInteger('quantity')->default(0);
            $table->unsignedTinyInteger('moderation_status')->default(0);
            $table->string('status')->default('active');
            $table->unsignedBigInteger('group_id')->default(0);
            $table->json('photos')->nullable();
            $table->json('raw')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'article']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rozetka_products');
    }
};
