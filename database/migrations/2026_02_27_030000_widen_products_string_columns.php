<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen string columns in products table to prevent SQLSTATE[22001] truncation errors.
 * Horoshop data can exceed VARCHAR(255) for titles, links, category paths, etc.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('title', 500)->nullable()->change();
            $table->string('category_path', 500)->nullable()->change();
            $table->string('slug', 500)->nullable()->change();
            $table->string('link', 1000)->nullable()->change();
            $table->string('presence', 500)->nullable()->change();
            $table->string('brand', 500)->nullable()->change();
            $table->string('color', 500)->nullable()->change();
            $table->string('size', 500)->nullable()->change();
            $table->string('article', 500)->change();
            $table->string('parent_article', 500)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('title', 255)->nullable()->change();
            $table->string('category_path', 255)->nullable()->change();
            $table->string('slug', 255)->nullable()->change();
            $table->string('link', 255)->nullable()->change();
            $table->string('presence', 255)->nullable()->change();
            $table->string('brand', 255)->nullable()->change();
            $table->string('color', 255)->nullable()->change();
            $table->string('size', 255)->nullable()->change();
            $table->string('article', 255)->change();
            $table->string('parent_article', 255)->nullable()->change();
        });
    }
};
