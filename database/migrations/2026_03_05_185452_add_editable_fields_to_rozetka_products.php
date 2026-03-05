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
            $table->text('description')->nullable()->after('title');
            $table->text('description_ua')->nullable()->after('description');
            $table->json('edited_fields')->nullable()->after('raw');
            $table->boolean('has_local_changes')->default(false)->after('edited_fields');
        });
    }

    public function down(): void
    {
        Schema::table('rozetka_products', function (Blueprint $table) {
            $table->dropColumn(['description', 'description_ua', 'edited_fields', 'has_local_changes']);
        });
    }
};
