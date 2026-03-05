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
        Schema::create('rozetka_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('rozetka_id')->unique()->index();
            $table->string('title_ua');
            $table->string('title_ru')->nullable();
            $table->unsignedBigInteger('parent_rozetka_id')->nullable()->index();
            $table->unsignedTinyInteger('level')->default(1);
            $table->string('mpath')->nullable();
            $table->string('full_path')->nullable();
            $table->boolean('is_vendor_required')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rozetka_categories');
    }
};
