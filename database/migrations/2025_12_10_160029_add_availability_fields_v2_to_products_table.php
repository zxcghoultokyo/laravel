<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // додаємо тільки ті колонки, яких ще нема,
            // щоб не впасти, якщо локально/на іншому серваку вони вже є

            if (!Schema::hasColumn('products', 'display_in_showcase')) {
                $table->boolean('display_in_showcase')->default(true)->index();
            }

            if (!Schema::hasColumn('products', 'presence')) {
                $table->string('presence')->nullable()->index();
            }

            if (!Schema::hasColumn('products', 'quantity')) {
                $table->unsignedInteger('quantity')->default(0)->index();
            }

            if (!Schema::hasColumn('products', 'popularity')) {
                $table->unsignedInteger('popularity')->default(0)->index();
            }

            if (!Schema::hasColumn('products', 'we_recommended')) {
                $table->boolean('we_recommended')->default(false)->index();
            }

            if (!Schema::hasColumn('products', 'color')) {
                $table->string('color')->nullable()->index();
            }

            if (!Schema::hasColumn('products', 'in_stock')) {
                $table->boolean('in_stock')->default(true)->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // обережно дропаємо тільки якщо існують

            if (Schema::hasColumn('products', 'display_in_showcase')) {
                $table->dropColumn('display_in_showcase');
            }

            if (Schema::hasColumn('products', 'presence')) {
                $table->dropColumn('presence');
            }

            if (Schema::hasColumn('products', 'quantity')) {
                $table->dropColumn('quantity');
            }

            if (Schema::hasColumn('products', 'popularity')) {
                $table->dropColumn('popularity');
            }

            if (Schema::hasColumn('products', 'we_recommended')) {
                $table->dropColumn('we_recommended');
            }

            if (Schema::hasColumn('products', 'color')) {
                $table->dropColumn('color');
            }

            if (Schema::hasColumn('products', 'in_stock')) {
                $table->dropColumn('in_stock');
            }
        });
    }
};
