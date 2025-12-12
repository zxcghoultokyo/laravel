<?php

namespace App\Console\Commands;

use App\Models\SearchEvalCase;
use App\Services\Horoshop\ProductService;
use Illuminate\Console\Command;

class SearchSeedEval extends Command
{
    protected $signature = 'search:seed-eval
        {--reset=0 : If 1, truncate table before seeding}
        {--autofill=0 : If 1, sets expected_product_ids to top-1 result from current search}
        {--language=uk : Language for seeded cases (uk/ru)}
        {--domain= : Optional domain}';

    protected $description = 'Seed default search eval cases into search_eval_cases (no tinker needed)';

    public function handle(ProductService $productService): int
    {
        $reset = (string) $this->option('reset') === '1';
        $autofill = (string) $this->option('autofill') === '1';
        $language = (string) ($this->option('language') ?: 'uk');
        $domain = $this->option('domain');
        $domain = $domain !== null && $domain !== '' ? (string) $domain : null;

        if ($reset) {
            SearchEvalCase::query()->truncate();
            $this->warn('search_eval_cases truncated.');
        }

        $rows = [
            ['query' => 'cat gen7 турнікет', 'notes' => 'CAT Gen7'],
            ['query' => 'турнікет cat', 'notes' => 'CAT турнікет'],
            ['query' => 'плитоноска multicam', 'notes' => 'Plate carrier multicam'],
            ['query' => 'бронеплити 4 клас', 'notes' => 'Plates class 4'],
            ['query' => 'аптечка ifak', 'notes' => 'IFAK'],
            ['query' => 'підсумок під турнікет', 'notes' => 'TQ pouch'],
            ['query' => 'earmor m32', 'notes' => 'Earmor M32'],
            ['query' => 'активні навушники', 'notes' => 'Active hearing protection'],
            ['query' => 'рюкзак defcon 5', 'notes' => 'Backpack Defcon 5'],
            ['query' => 'плитоноска атака архангел', 'notes' => 'ATAKA Archangel'],
            ['query' => 'олива плитоноска', 'notes' => 'Color olive'],
            ['query' => 'чорний турнікет', 'notes' => 'Color black'],
        ];

        $created = 0;

        foreach ($rows as $r) {
            $case = SearchEvalCase::create([
                'query' => $r['query'],
                'expected_product_ids' => [],
                'language' => $language,
                'domain' => $domain,
                'notes' => $r['notes'],
                'is_active' => true,
            ]);
            $created++;

            if ($autofill) {
                $results = $productService->searchByText($case->query, null, $case->language);
                $topId = $results[0]['id'] ?? null;

                if ($topId) {
                    $case->expected_product_ids = [(int) $topId];
                    $case->save();
                    $this->line("case {$case->id}: expected={$topId}");
                } else {
                    $this->line("case {$case->id}: no results");
                }
            }
        }

        $this->info("Created eval cases: {$created}");

        return self::SUCCESS;
    }
}
