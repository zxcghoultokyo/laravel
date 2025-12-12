<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'brand')) {
                $table->string('brand')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        // no-op (safe in prod)
    }
};
