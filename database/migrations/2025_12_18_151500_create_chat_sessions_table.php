<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chat_sessions')) {
            return;
        }

        Schema::create('chat_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('session_id')->unique();
            $table->string('last_intent')->nullable();
            $table->text('last_user_query')->nullable();
            $table->integer('messages_count')->default(0);
            $table->string('language', 8)->nullable();
            $table->string('status')->default('open'); // open, closed, flagged
            $table->json('meta')->nullable(); // domain, user_ip, etc
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'last_message_at']);
            $table->index('last_intent');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_sessions');
    }
};
