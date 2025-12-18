<?php

namespace App\Console\Commands;

use App\Services\Search\MeiliClient;
use Illuminate\Console\Command;

class TestMeiliDirectCommand extends Command
{
    protected $signature = 'test:meili-direct {query}';
    protected $description = 'Тестування прямого Meilisearch з детальною інформацією';

    public function __construct(private MeiliClient $meiliClient)
    {
        parent::__construct();
    }

    public function handle()
    {
        $query = $this->argument('query');
        
        $this->info("=== Тестування Meilisearch: '{$query}' ===");
        $this->newLine();

        try {
            $client = $this->meiliClient->client();
            $index = $client->index('products');
            
            // 1. Статистика індексу
            $this->info('📊 Статистика індексу:');
            $stats = $index->stats();
            $this->line("  Документів: " . ($stats['numberOfDocuments'] ?? 0));
            $this->line("  Індексується: " . ($stats['isIndexing'] ? 'Так' : 'Ні'));
            $this->newLine();
            
            // 2. Налаштування індексу
            $this->info('⚙️ Налаштування індексу:');
            $settings = $index->getSettings();
            
            $this->line("  Searchable attributes:");
            if (!empty($settings['searchableAttributes'])) {
                foreach ($settings['searchableAttributes'] as $attr) {
                    $this->line("    - {$attr}");
                }
            } else {
                $this->warn("    ⚠ Не налаштовано! (пошук працює по всіх полях)");
            }
            
            $this->line("  Filterable attributes:");
            if (!empty($settings['filterableAttributes'])) {
                foreach ($settings['filterableAttributes'] as $attr) {
                    $this->line("    - {$attr}");
                }
            } else {
                $this->warn("    ⚠ Не налаштовано!");
            }
            $this->newLine();
            
            // 3. Пошук без фільтрів
            $this->info("🔍 Пошук '{$query}' (без фільтрів):");
            $result1 = $index->search($query, ['limit' => 5]);
            $hits1 = $result1->getHits();
            $this->line("  Знайдено: " . count($hits1));
            if (count($hits1) > 0) {
                foreach ($hits1 as $hit) {
                    $title = $hit['title'] ?? 'N/A';
                    $article = $hit['article'] ?? 'N/A';
                    $inStock = $hit['in_stock'] ?? false;
                    $stock = $inStock ? '✓' : '✗';
                    $this->line("    {$stock} [{$article}] {$title}");
                }
            }
            $this->newLine();
            
            // 4. Пошук з фільтром in_stock
            $this->info("🔍 Пошук '{$query}' (тільки in_stock):");
            $result2 = $index->search($query, [
                'limit' => 5,
                'filter' => 'in_stock = true'
            ]);
            $hits2 = $result2->getHits();
            $this->line("  Знайдено: " . count($hits2));
            if (count($hits2) > 0) {
                foreach ($hits2 as $hit) {
                    $title = $hit['title'] ?? 'N/A';
                    $article = $hit['article'] ?? 'N/A';
                    $price = $hit['price'] ?? 'N/A';
                    $this->line("    [{$article}] {$title} ({$price} грн)");
                }
            }
            $this->newLine();
            
            // 5. Пошук пустого запиту (отримати будь-які документи)
            $this->info("🔍 Пусті результати (отримати будь-які 5 документів):");
            $result3 = $index->search('', [
                'limit' => 5,
                'filter' => 'in_stock = true'
            ]);
            $hits3 = $result3->getHits();
            $this->line("  Всього доступно: " . count($hits3));
            if (count($hits3) > 0) {
                $this->line("  Приклади:");
                foreach (array_slice($hits3, 0, 3) as $hit) {
                    $title = $hit['title'] ?? 'N/A';
                    $article = $hit['article'] ?? 'N/A';
                    $this->line("    [{$article}] {$title}");
                }
            } else {
                $this->error("  ✗ Навіть пустий запит нічого не повертає!");
                $this->warn("    Можливо індекс пошкоджений або не налаштований фільтр 'in_stock'");
            }
            
        } catch (\Exception $e) {
            $this->error("✗ Помилка: " . $e->getMessage());
            $this->newLine();
            $this->line("Stack trace:");
            $this->line($e->getTraceAsString());
        }

        return 0;
    }
}
