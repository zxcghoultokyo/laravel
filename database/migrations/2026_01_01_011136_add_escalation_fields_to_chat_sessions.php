<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add escalation fields to active_chat_sessions
        if (Schema::hasTable('active_chat_sessions')) {
            Schema::table('active_chat_sessions', function (Blueprint $table) {
                if (!Schema::hasColumn('active_chat_sessions', 'needs_human')) {
                    $table->boolean('needs_human')->default(false)->after('status');
                }
                if (!Schema::hasColumn('active_chat_sessions', 'escalation_reason')) {
                    $table->string('escalation_reason', 255)->nullable()->after('needs_human');
                }
                if (!Schema::hasColumn('active_chat_sessions', 'escalated_at')) {
                    $table->timestamp('escalated_at')->nullable()->after('escalation_reason');
                }
                if (!Schema::hasColumn('active_chat_sessions', 'notification_sent')) {
                    $table->boolean('notification_sent')->default(false)->after('escalated_at');
                }
            });
        }

        // Add escalation fields to chat_sessions if exists
        if (Schema::hasTable('chat_sessions')) {
            Schema::table('chat_sessions', function (Blueprint $table) {
                if (!Schema::hasColumn('chat_sessions', 'needs_human')) {
                    $table->boolean('needs_human')->default(false)->after('session_id');
                }
                if (!Schema::hasColumn('chat_sessions', 'escalation_reason')) {
                    $table->string('escalation_reason', 255)->nullable()->after('needs_human');
                }
                if (!Schema::hasColumn('chat_sessions', 'operator_id')) {
                    $table->unsignedBigInteger('operator_id')->nullable()->after('escalation_reason');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('active_chat_sessions')) {
            Schema::table('active_chat_sessions', function (Blueprint $table) {
                $table->dropColumn(['needs_human', 'escalation_reason', 'escalated_at', 'notification_sent']);
            });
        }

        if (Schema::hasTable('chat_sessions')) {
            Schema::table('chat_sessions', function (Blueprint $table) {
                $table->dropColumn(['needs_human', 'escalation_reason', 'operator_id']);
            });
        }
    }
};
