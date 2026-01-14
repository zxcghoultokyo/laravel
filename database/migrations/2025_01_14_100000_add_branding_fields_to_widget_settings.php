<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('widget_settings', function (Blueprint $table) {
            // Bot branding
            $table->string('bot_name', 100)->nullable()->after('logo_url');
            $table->string('bot_avatar_url', 500)->nullable()->after('bot_name');
            $table->string('bot_status_text', 100)->nullable()->after('bot_avatar_url');
            
            // Additional styling
            $table->string('font_family', 100)->nullable()->after('border_radius');
            $table->boolean('show_shadow')->default(true)->after('font_family');
            
            // Greetings settings (JSON array of greeting objects)
            $table->json('greetings')->nullable()->after('welcome_message');
            
            // Tone/Persona
            $table->string('tone', 50)->default('official')->after('greetings'); // official, spartan, friendly
            $table->json('brand_rules')->nullable()->after('tone'); // Array of brand rules
        });
    }

    public function down(): void
    {
        Schema::table('widget_settings', function (Blueprint $table) {
            $table->dropColumn([
                'bot_name',
                'bot_avatar_url', 
                'bot_status_text',
                'font_family',
                'show_shadow',
                'greetings',
                'tone',
                'brand_rules',
            ]);
        });
    }
};
