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
        Schema::table('rozetka_products', function (Blueprint $table) {
            $table->dropUnique(['rozetka_item_id']);
            $table->unsignedBigInteger('rozetka_item_id')->nullable()->index()->change();
        });
    }

    public function down(): void
    {
        Schema::table('rozetka_products', function (Blueprint $table) {
            $table->unsignedBigInteger('rozetka_item_id')->nullable(false)->change();
        });
    }
};
