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
            $table->string('export_status', 20)->nullable()->index()->after('status');
            $table->unsignedBigInteger('local_product_id')->nullable()->index()->after('export_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rozetka_products', function (Blueprint $table) {
            $table->dropColumn(['export_status', 'local_product_id']);
        });
    }
};
