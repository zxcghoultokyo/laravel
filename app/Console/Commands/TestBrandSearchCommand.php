<?php

namespace App\Console\Commands;

use App\Services\Agent\AgentOrchestrator;
use Illuminate\Console\Command;

class TestBrandSearchCommand extends Command
{
    protected $signature = 'test:brand-search {query?} {--connection=}';
    protected $description = 'Test brand search functionality after brand priority fix';

    public function handle(AgentOrchestrator $orchestrator): int
    {
        // Optionally override DB connection (e.g., --connection=mysql)
        $conn = $this->option('connection');
        if ($conn) {
            config(['database.default' => $conn]);
            $this->line("🔌 Using DB connection: {$conn}");
        }

        $testCases = [
            'hoffmann',
            'атака',
            'атака плитоноска',
            'kombat uk',
            'плитоноска', // Should show mixed brands (not explicit)
        ];
        
        $query = $this->argument('query');
        if ($query) {
            $testCases = [$query];
        }
        
        $this->info('🧪 Testing Brand Search Priority Fix');
        $this->newLine();
        
        foreach ($testCases as $testQuery) {
            $this->info("📝 Query: \"{$testQuery}\"");
            $this->line(str_repeat('─', 60));
            
            try {
                $result = $orchestrator->handle($testQuery, []);
                
                $products = $result['products'] ?? [];
                $meta = $result['meta'] ?? [];
                
                $this->line("✅ Found: " . count($products) . " products");
                
                if (isset($meta['search_debug']['detected_brand'])) {
                    $this->line("🏷️  Detected brand: " . $meta['search_debug']['detected_brand']);
                }
                
                if (isset($meta['search_debug']['brand_filtered'])) {
                    $this->line("🔍 Brand filter applied: YES");
                }
                
                // Show brands in results
                $brands = [];
                foreach ($products as $p) {
                    $brand = $p['brand'] ?? 'N/A';
                    $brands[$brand] = ($brands[$brand] ?? 0) + 1;
                }
                
                $this->line("📊 Brands in results:");
                foreach ($brands as $brand => $count) {
                    $this->line("   • {$brand}: {$count} products");
                }
                
                // Show first 3 products
                $this->line("🎯 Top 3 products:");
                foreach (array_slice($products, 0, 3) as $idx => $p) {
                    $title = mb_substr($p['title'] ?? '', 0, 50);
                    $brand = $p['brand'] ?? 'N/A';
                    $popularity = $p['popularity'] ?? 0;
                    $this->line("   " . ($idx + 1) . ". [{$brand}] {$title} (pop: {$popularity})");
                }
                
                $this->newLine();
                
            } catch (\Exception $e) {
                $this->error("❌ Error: " . $e->getMessage());
                $this->newLine();
            }
        }
        
        $this->info('✅ Brand search tests completed!');
        $this->newLine();
        $this->line('Expected behavior:');
        $this->line('  • "hoffmann" → only HOFFMANN products');
        $this->line('  • "атака" → only АТАКА products');
        $this->line('  • "плитоноска" → mixed brands (not explicit brand query)');
        
        return Command::SUCCESS;
    }
}
