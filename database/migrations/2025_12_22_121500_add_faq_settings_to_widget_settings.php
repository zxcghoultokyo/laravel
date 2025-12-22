<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('widget_settings', function (Blueprint $table) {
            $table->boolean('enable_faq_custom_content')->default(true)->after('enable_faq_from_horoshop');

            $table->string('faq_payment_delivery_url')->nullable()->after('enable_faq_custom_content');
            $table->text('faq_payment_delivery_text')->nullable()->after('faq_payment_delivery_url');

            $table->string('faq_returns_url')->nullable()->after('faq_payment_delivery_text');
            $table->text('faq_returns_text')->nullable()->after('faq_returns_url');

            $table->string('faq_contacts_url')->nullable()->after('faq_returns_text');
            $table->text('faq_contacts_text')->nullable()->after('faq_contacts_url');

            $table->string('faq_about_url')->nullable()->after('faq_contacts_text');
            $table->text('faq_about_text')->nullable()->after('faq_about_url');
        });
    }

    public function down(): void
    {
        Schema::table('widget_settings', function (Blueprint $table) {
            $table->dropColumn([
                'enable_faq_custom_content',
                'faq_payment_delivery_url', 'faq_payment_delivery_text',
                'faq_returns_url', 'faq_returns_text',
                'faq_contacts_url', 'faq_contacts_text',
                'faq_about_url', 'faq_about_text',
            ]);
        });
    }
};
