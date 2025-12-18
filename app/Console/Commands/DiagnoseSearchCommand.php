<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use MeiliSearch\Client;

class DiagnoseSearchCommand extends Command
{
    protected $signature = 'diagnose:search';
    protected $description = 'Діагностика системи пошуку: БД, Meilisearch, OpenAI';

    public function handle()
    {
        $this->info('=== Діагностика системи пошуку ===');
        $this->newLine();

        // 1. База даних
        $this->info('📊 База даних:');
        try {
            $totalProducts = Product::count();
            $inStockProducts = Product::where('in_stock', true)->count();
            $this->line("  ✓ Загалом товарів: {$totalProducts}");
            $this->line("  ✓ В наявності: {$inStockProducts}");

            if ($totalProducts === 0) {
                $this->warn('  ⚠ База даних пуста! Запустіть: php artisan sync:horoshop-products');
            } else {
                // Приклади товарів
                $samples = Product::where('in_stock', true)->limit(3)->pluck('title')->toArray();
                $this->line('  ✓ Приклади:');
                foreach ($samples as $title) {
                    $this->line("    - {$title}");
                }
            }
        } catch (\Exception $e) {
            $this->error('  ✗ Помилка БД: ' . $e->getMessage());
        }

        $this->newLine();

        // 2. Meilisearch
        $this->info('🔍 Meilisearch:');
        try {
            $meiliEnabled = Config::get('meilisearch.enabled', false);
            $meiliHost = Config::get('meilisearch.host');
            $meiliKey = Config::get('meilisearch.key');

            $this->line("  ✓ Enabled: " . ($meiliEnabled ? 'YES' : 'NO'));
            $this->line("  ✓ Host: {$meiliHost}");
            $this->line("  ✓ Key: " . (empty($meiliKey) ? 'NOT SET' : 'SET'));

            if ($meiliEnabled) {
                $client = new Client($meiliHost, $meiliKey);
                $stats = $client->index('products')->stats();
                $documentsCount = $stats['numberOfDocuments'] ?? 0;

                $this->line("  ✓ Індекс 'products': {$documentsCount} документів");

                if ($documentsCount === 0) {
                    $this->warn('  ⚠ Індекс пустий! Запустіть: php artisan meili:setup-products && php artisan meili:reindex-products');
                }
            } else {
                $this->warn('  ⚠ Meilisearch вимкнено в конфігурації!');
            }
        } catch (\Exception $e) {
            $this->error('  ✗ Помилка Meili: ' . $e->getMessage());
        }

        $this->newLine();

        // 3. OpenAI
        $this->info('🤖 OpenAI:');
        $openaiKey = Config::get('services.openai.key');
        $openaiModel = Config::get('services.openai.model');
        $openaiUrl = Config::get('services.openai.base_url');

        $this->line("  ✓ API Key: " . (empty($openaiKey) ? 'NOT SET' : 'SET (' . substr($openaiKey, 0, 10) . '...)'));
        $this->line("  ✓ Model: {$openaiModel}");
        $this->line("  ✓ Base URL: {$openaiUrl}");

        if (empty($openaiKey)) {
            $this->warn('  ⚠ OpenAI API key не встановлено! Встановіть OPENAI_API_KEY в .env');
        }

        $this->newLine();

        // 4. Рекомендації
        $this->info('💡 Рекомендації:');

        if ($totalProducts === 0) {
            $this->warn('  1. Синхронізуйте продукти: php artisan sync:horoshop-products');
        }

        if (($stats['numberOfDocuments'] ?? 0) === 0 && $totalProducts > 0) {
            $this->warn('  2. Створіть індекс: php artisan meili:setup-products');
            $this->warn('  3. Проіндексуйте: php artisan meili:reindex-products');
        }

        if (empty($openaiKey)) {
            $this->warn('  4. Встановіть OPENAI_API_KEY в .env');
        }

        if ($totalProducts > 0 && ($stats['numberOfDocuments'] ?? 0) > 0 && !empty($openaiKey)) {
            $this->info('  ✓ Все налаштовано! Система готова до роботи.');
        }

        $this->newLine();

        return 0;
    }
}
