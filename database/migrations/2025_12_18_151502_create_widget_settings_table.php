<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('widget_settings')) {
            return;
        }

        Schema::create('widget_settings', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->unique(); // для multi-shop
            $table->string('primary_color')->default('#2563eb');
            $table->string('text_color')->default('#ffffff');
            $table->string('position')->default('right'); // left, right
            $table->string('start_state')->default('closed'); // open, closed
            $table->integer('border_radius')->default(12);
            $table->string('logo_url')->nullable();
            $table->text('welcome_message')->default('Вітаю! 👋 Я AILure Асистент. Напишіть, що шукаєте.');
            $table->string('input_placeholder')->default('Напишіть, що шукаєте...');
            $table->text('consent_notice')->nullable();
            $table->boolean('enabled')->default(true);
            $table->string('api_token')->nullable();
            $table->timestamps();

            $table->index('domain');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widget_settings');
    }
};
