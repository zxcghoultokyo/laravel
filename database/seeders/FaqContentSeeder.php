<?php

namespace Database\Seeders;

use App\Models\WidgetSettings;
use App\Services\Support\FaqContentIngestService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class FaqContentSeeder extends Seeder
{
    /**
     * Fill FAQ content in WidgetSettings.
     * 
     * Run: php artisan db:seed --class=FaqContentSeeder
     */
    public function run(): void
    {
        $settings = WidgetSettings::first();

        if (!$settings) {
            $this->command?->warn('No WidgetSettings found. Creating default...');
            $settings = WidgetSettings::create([
                'domain' => 'contractor.kiev.ua',
                'enabled' => true,
            ]);
        }

        // Set FAQ URLs if not already set
        if (empty($settings->faq_payment_delivery_url)) {
            $settings->faq_payment_delivery_url = 'https://contractor.kiev.ua/oplata-i-dostavka/';
        }
        if (empty($settings->faq_contacts_url)) {
            $settings->faq_contacts_url = 'https://contractor.kiev.ua/kontaktna-informatsiya/';
        }
        if (empty($settings->faq_about_url)) {
            $settings->faq_about_url = 'https://contractor.kiev.ua/pro-nas/';
        }

        $settings->save();
        $this->command?->info('FAQ URLs set.');

        // Try to ingest content from URLs
        try {
            $ingestService = app(FaqContentIngestService::class);
            $ingestService->ingest($settings);
            $this->command?->info('FAQ content ingested from URLs.');
        } catch (\Throwable $e) {
            $this->command?->warn('Failed to ingest FAQ: ' . $e->getMessage());
            Log::warning('FaqContentSeeder: ingest failed', ['error' => $e->getMessage()]);
        }

        $settings->refresh();

        // Manually set returns text if page doesn't exist
        if (empty($settings->faq_returns_text)) {
            $settings->faq_returns_text = <<<TEXT
Повернення та обмін

Ми приймаємо повернення та обмін товарів протягом 14 днів з моменту отримання.

Умови повернення:
• Товар має бути в оригінальній упаковці
• Товар не використовувався
• Наявність чеку або підтвердження замовлення

Для оформлення повернення зверніться до нас:
• Телефон: +380 63 631 9919
• Telegram: @sturmtig
• Email: vigser2@gmail.com
TEXT;
            $settings->save();
            $this->command?->info('Returns text set manually.');
        }

        // Summary
        $this->command?->table(
            ['Field', 'Length'],
            [
                ['faq_payment_delivery_text', strlen($settings->faq_payment_delivery_text ?? '')],
                ['faq_returns_text', strlen($settings->faq_returns_text ?? '')],
                ['faq_contacts_text', strlen($settings->faq_contacts_text ?? '')],
                ['faq_about_text', strlen($settings->faq_about_text ?? '')],
            ]
        );

        $this->command?->info('FaqContentSeeder completed!');
    }
}
