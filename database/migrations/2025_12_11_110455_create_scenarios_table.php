<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('scenarios', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // наприклад: TACTICAL_MEDICINE, PRODUCT_SEARCH, ORDER_STATUS
            $table->string('name');           // Людська назва
            $table->text('description')->nullable(); // Опис для ЛЛМ, що це за сценарій
            $table->string('handler_class')->nullable(); // FQCN хендлера
            $table->boolean('is_active')->default(true);

            // JSON-конфіг: джерела даних, правила скорингу, дод.інструкції для ЛЛМ
            $table->json('config')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scenarios');
    }
};
