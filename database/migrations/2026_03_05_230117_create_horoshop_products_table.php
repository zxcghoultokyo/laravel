<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('horoshop_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('article', 500);
            $table->string('parent_article', 500)->nullable();

            // Basic product info
            $table->string('title', 500)->nullable();
            $table->json('title_json')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('price_old', 12, 2)->nullable();
            $table->string('brand', 255)->nullable();
            $table->string('color', 255)->nullable();
            $table->string('size', 255)->nullable();
            $table->string('category_path', 500)->nullable();

            // Stock
            $table->boolean('in_stock')->default(false);
            $table->unsignedInteger('quantity')->default(0);
            $table->string('presence', 255)->nullable();
            $table->boolean('display_in_showcase')->default(true);

            // Content
            $table->text('description_ua')->nullable();
            $table->text('description_ru')->nullable();
            $table->text('short_description_ua')->nullable();
            $table->text('short_description_ru')->nullable();

            // Images
            $table->json('images')->nullable();
            $table->json('gallery_common')->nullable();

            // Characteristics — stored as full JSON
            $table->json('characteristics')->nullable();

            // SEO
            $table->json('seo_title')->nullable();
            $table->json('seo_keywords')->nullable();
            $table->json('seo_description')->nullable();

            // Misc
            $table->string('slug', 500)->nullable();
            $table->string('link', 1000)->nullable();
            $table->unsignedInteger('popularity')->default(0);
            $table->boolean('we_recommended')->default(false);
            $table->json('icons')->nullable();
            $table->string('mod_title', 500)->nullable();

            // Full raw payload from Horoshop
            $table->json('raw')->nullable();

            // Match with Rozetka
            $table->unsignedBigInteger('rozetka_product_id')->nullable()->index();

            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'article'], 'horoshop_products_tenant_article_unique');
            $table->index(['tenant_id', 'parent_article']);
            $table->index('brand');
            $table->index('category_path');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('horoshop_products');
    }
};
