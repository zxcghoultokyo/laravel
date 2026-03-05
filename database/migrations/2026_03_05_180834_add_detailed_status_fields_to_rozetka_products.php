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
            $table->smallInteger('upload_status')->nullable()->index()->after('moderation_status');
            $table->string('upload_status_title', 100)->nullable()->after('upload_status');
            $table->smallInteger('rz_status')->nullable()->after('upload_status_title');
            $table->smallInteger('rz_sell_status')->nullable()->after('rz_status');
            $table->smallInteger('available')->nullable()->index()->after('rz_sell_status');
            $table->string('available_title', 100)->nullable()->after('available');
            $table->json('blocked_reasons')->nullable()->after('available_title');
            $table->smallInteger('change_status')->nullable()->after('blocked_reasons');
            $table->string('producer_name', 255)->nullable()->after('change_status');
            $table->string('url', 500)->nullable()->after('producer_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rozetka_products', function (Blueprint $table) {
            $table->dropColumn([
                'upload_status', 'upload_status_title', 'rz_status', 'rz_sell_status',
                'available', 'available_title', 'blocked_reasons', 'change_status',
                'producer_name', 'url',
            ]);
        });
    }
};
