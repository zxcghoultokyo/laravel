<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('widget_settings', function (Blueprint $table) {
            // Shop contact settings for order issues
            $table->string('shop_phone')->default('+380 63 631 9919')->after('api_token');
            $table->string('callback_form_url')->default('https://contractor.kiev.ua/kontaktna-informatsiya/#callback')->after('shop_phone');
            
            // Delivery tracking settings
            $table->string('nova_poshta_tracking_url')->default('https://tracking.novaposhta.ua/')->after('callback_form_url');
            $table->boolean('enable_delivery_tracking')->default(true)->after('nova_poshta_tracking_url');
            
            // FAQ/Pages integration
            $table->boolean('enable_faq_from_horoshop')->default(true)->after('enable_delivery_tracking');
            
            // Horoshop integration settings
            $table->string('horoshop_domain')->nullable()->after('enable_faq_from_horoshop');
            $table->string('horoshop_api_login')->nullable()->after('horoshop_domain');
            $table->string('horoshop_api_password')->nullable()->after('horoshop_api_login');
        });
    }

    public function down(): void
    {
        Schema::table('widget_settings', function (Blueprint $table) {
            $table->dropColumn([
                'shop_phone',
                'callback_form_url',
                'nova_poshta_tracking_url',
                'enable_delivery_tracking',
                'enable_faq_from_horoshop',
                'horoshop_domain',
                'horoshop_api_login',
                'horoshop_api_password',
            ]);
        });
    }
};
