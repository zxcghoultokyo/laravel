<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('widget_settings', function (Blueprint $table) {
            // Glow color for avatar animation (defaults to primary color if null)
            $table->string('glow_color', 20)->nullable()->after('bot_avatar_url');
            // Base64 avatar for serverless environments (Laravel Cloud)
            $table->mediumText('bot_avatar_base64')->nullable()->after('glow_color');
        });
    }

    public function down(): void
    {
        Schema::table('widget_settings', function (Blueprint $table) {
            $table->dropColumn(['glow_color', 'bot_avatar_base64']);
        });
    }
};
