<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('widget_settings', function (Blueprint $table) {
            // Icon size: small (52px), medium (64px), large (72px)
            $table->string('icon_size', 20)->default('medium')->after('glow_color');
            // Icon shape: circle, rounded-square, squircle
            $table->string('icon_style', 30)->default('circle')->after('icon_size');
            // Entrance animation: none, bounce, scale, slide
            $table->string('icon_entrance_animation', 30)->default('bounce')->after('icon_style');
            // Attention effect: none, glow, pulse-ring, wiggle
            $table->string('icon_attention_effect', 30)->default('glow')->after('icon_entrance_animation');
            // Attention delay in seconds (how long after page load to start attention effect)
            $table->integer('icon_attention_delay')->default(5)->after('icon_attention_effect');
        });
    }

    public function down(): void
    {
        Schema::table('widget_settings', function (Blueprint $table) {
            $table->dropColumn([
                'icon_size',
                'icon_style',
                'icon_entrance_animation',
                'icon_attention_effect',
                'icon_attention_delay',
            ]);
        });
    }
};
