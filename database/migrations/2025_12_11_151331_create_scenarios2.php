<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('scenarios', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();       // наприклад: "product_help", "need_order"
            $table->string('title');                // Людяна назва
            $table->string('type')->default('chat');// тип: chat / flow / system
            $table->boolean('is_active')->default(true);
            $table->json('config')->nullable();     // довільний JSON (налаштування, тексти, і т.д.)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scenarios');
    }
};
