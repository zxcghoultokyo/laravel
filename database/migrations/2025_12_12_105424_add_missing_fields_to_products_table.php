<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'parent_article')) {
                $table->string('parent_article')->nullable()->after('article');
            }

            if (!Schema::hasColumn('products', 'presence')) {
                $table->string('presence')->nullable()->after('raw');
            }

            if (!Schema::hasColumn('products', 'quantity')) {
                $table->integer('quantity')->default(0)->after('presence');
            }

            if (!Schema::hasColumn('products', 'popularity')) {
                $table->integer('popularity')->default(0)->after('quantity');
            }

            if (!Schema::hasColumn('products', 'we_recommended')) {
                $table->boolean('we_recommended')->default(false)->after('popularity');
            }

            if (!Schema::hasColumn('products', 'display_in_showcase')) {
                $table->boolean('display_in_showcase')->default(false)->after('we_recommended');
            }

            if (!Schema::hasColumn('products', 'in_stock')) {
                $table->boolean('in_stock')->default(false)->after('display_in_showcase');
            }

            if (!Schema::hasColumn('products', 'color')) {
                $table->string('color')->nullable()->after('in_stock');
            }
        });
    }

    public function down(): void
    {
        // down можна не чіпати, або потім акуратно зробимо dropColumn
    }
};
