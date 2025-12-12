<?php

namespace App\Console\Commands;

use App\Models\SearchEvalCase;
use App\Services\Horoshop\ProductService;
use Illuminate\Console\Command;

class SearchEvaluate extends Command
{
    protected $signature = 'search:evaluate {--k=10 : Hit@k / MRR@k} {--only-active=1}';
    protected $description = 'Evaluate product search quality on a small offline test set (hit@k, MRR)';

    public function handle(ProductService $productService): int
    {
        $k = max(1, (int) $this->option('k'));
        $onlyActive = (string) $this->option('only-active') !== '0';

        $casesQ = SearchEvalCase::query();
        if ($onlyActive) {
            $casesQ->where('is_active', true);
        }
        $cases = $casesQ->orderBy('id')->get();

        if ($cases->isEmpty()) {
            $this->warn('No eval cases found. Add rows to search_eval_cases first.');
            return self::SUCCESS;
        }

        $hit = 0;
        $mrrSum = 0.0;
        $total = 0;

        foreach ($cases as $case) {
            $total++;
            $expected = (array) ($case->expected_product_ids ?? []);
            $expected = array_values(array_filter(array_map('intval', $expected)));

            $results = $productService->searchByText($case->query, null, $case->language ?? 'uk');
            $ids = array_map(fn ($p) => (int) ($p['id'] ?? 0), array_slice($results, 0, $k));

            $rank = null;
            foreach ($ids as $i => $id) {
                if ($id !== 0 && in_array($id, $expected, true)) {
                    $rank = $i + 1;
                    break;
                }
            }

            if ($rank !== null) {
                $hit++;
                $mrrSum += 1.0 / $rank;
            }

            $this->line(sprintf(
                '#%d query="%s" hit=%s rank=%s top_ids=[%s] expected=[%s]',
                $case->id,
                mb_strimwidth($case->query, 0, 90, '…'),
                $rank !== null ? '1' : '0',
                $rank !== null ? (string) $rank : '-',
                implode(',', $ids),
                implode(',', $expected)
            ));
        }

        $hitAtK = $hit / max(1, $total);
        $mrrAtK = $mrrSum / max(1, $total);

        $this->newLine();
        $this->info(sprintf('Total: %d | hit@%d: %.3f | MRR@%d: %.3f', $total, $k, $hitAtK, $k, $mrrAtK));

        return self::SUCCESS;
    }
}
