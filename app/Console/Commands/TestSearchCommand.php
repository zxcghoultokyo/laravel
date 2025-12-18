<?php

namespace App\Console\Commands;

use App\Services\Agent\AgentOrchestrator;
use App\Services\Agent\Tools\MeiliProductSearchTool;
use App\Services\Search\MeiliClient;
use Illuminate\Console\Command;

class TestSearchCommand extends Command
{
    protected $signature = 'test:search {query}';
    protected $description = 'Тестування пошуку на всіх рівнях';

    public function __construct(
        private MeiliClient $meiliClient,
        private MeiliProductSearchTool $searchTool,
        private AgentOrchestrator $orchestrator
    ) {
        parent::__construct();
    }

    public function handle()
    {
        $query = $this->argument('query');
        
        $this->info("=== Тестування пошуку: '{$query}' ===");
        $this->newLine();

        // 1. Прямий запит до Meilisearch
        $this->info('🔍 Рівень 1: Прямий Meilisearch');
        try {
            $index = $this->meiliClient->client()->index('products');
            $result = $index->search($query, [
                'limit' => 10,
                'filter' => 'in_stock = true',
                'attributesToRetrieve' => ['id', 'article', 'title', 'price']
            ]);
            
            $hits = $result->getHits();
            $this->line("  Знайдено: " . count($hits));
            
            if (count($hits) > 0) {
                $this->line("  Перші 3 результати:");
                foreach (array_slice($hits, 0, 3) as $hit) {
                    $this->line("    - [{$hit['article']}] {$hit['title']} ({$hit['price']} грн)");
                }
            } else {
                $this->warn("  ⚠ Нічого не знайдено!");
            }
        } catch (\Exception $e) {
            $this->error("  ✗ Помилка: " . $e->getMessage());
        }
        
        $this->newLine();

        // 2. Через MeiliProductSearchTool
        $this->info('🔧 Рівень 2: MeiliProductSearchTool');
        try {
            $candidates = $this->searchTool->search($query, [], 10);
            $this->line("  Знайдено: " . count($candidates));
            
            if (count($candidates) > 0) {
                $this->line("  Перші 3 результати:");
                foreach (array_slice($candidates, 0, 3) as $hit) {
                    $this->line("    - [{$hit['article']}] {$hit['title']} ({$hit['price']} грн)");
                }
            } else {
                $this->warn("  ⚠ Нічого не знайдено!");
            }
        } catch (\Exception $e) {
            $this->error("  ✗ Помилка: " . $e->getMessage());
        }
        
        $this->newLine();

        // 3. Через AgentOrchestrator
        $this->info('🤖 Рівень 3: AgentOrchestrator');
        try {
            $result = $this->orchestrator->handle($query, []);
            
            $this->line("  Intent: " . ($result['meta']['intent'] ?? 'unknown'));
            $this->line("  Refined query: " . ($result['meta']['refined_query'] ?? 'null'));
            $this->line("  Products shown: " . ($result['meta']['products_shown'] ?? 0));
            $this->line("  Candidates found: " . ($result['meta']['search_debug']['candidates_found'] ?? 0));
            
            if (!empty($result['products'])) {
                $this->line("  Перші 3 продукти:");
                foreach (array_slice($result['products'], 0, 3) as $product) {
                    $this->line("    - [{$product['article']}] {$product['title']} ({$product['price']} грн)");
                }
            } else {
                $this->warn("  ⚠ Жодного продукту не повернуто!");
            }
            
            if (!empty($result['meta']['search_debug']['steps'])) {
                $this->newLine();
                $this->line("  Кроки виконання:");
                foreach ($result['meta']['search_debug']['steps'] as $step) {
                    $stepName = $step['step'] ?? 'unknown';
                    $duration = $step['duration_ms'] ?? 0;
                    $this->line("    • {$stepName}: {$duration}ms");
                    
                    if (isset($step['candidates_found'])) {
                        $this->line("      → знайдено: {$step['candidates_found']}");
                    }
                    if (isset($step['before'], $step['after'])) {
                        $this->line("      → до: {$step['before']}, після: {$step['after']}");
                    }
                }
            }
        } catch (\Exception $e) {
            $this->error("  ✗ Помилка: " . $e->getMessage());
            $this->error("  Stack trace:");
            $this->line($e->getTraceAsString());
        }
        
        $this->newLine();

        return 0;
    }
}
