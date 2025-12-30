<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;

class DiagnoseSizesCommand extends Command
{
    protected $signature = 'products:diagnose-sizes 
                            {--sample=5 : Кількість прикладів для показу}';

    protected $description = 'Діагностика розмірів товарів - перевірка структури raw та наявних даних';

    public function handle(): int
    {
        $this->info("🔍 ДІАГНОСТИКА РОЗМІРІВ");
        $this->newLine();

        // Загальна статистика
        $total = Product::count();
        $withRaw = Product::whereNotNull('raw')->count();
        $withSize = Product::whereNotNull('size')->where('size', '!=', '')->count();

        $this->table(['Метрика', 'Значення'], [
            ['Всього товарів', $total],
            ['З raw полем', $withRaw],
            ['З size полем', $withSize],
            ['% з розміром', $total > 0 ? round($withSize / $total * 100, 2) . '%' : '0%'],
        ]);

        $this->newLine();

        // Перевіримо структуру raw
        $this->info("📦 Перевірка структури raw поля:");

        $withRozmir = 0;
        $withSelect = 0;
        $withCharacteristics = 0;
        $rozmirExamples = [];

        Product::whereNotNull('raw')->chunk(500, function ($products) use (&$withRozmir, &$withSelect, &$withCharacteristics, &$rozmirExamples) {
            foreach ($products as $product) {
                $raw = $product->raw;
                if (!is_array($raw)) continue;

                if (!empty($raw['Rozmir'])) {
                    $withRozmir++;
                    if (count($rozmirExamples) < 5) {
                        $rozmirExamples[] = [
                            'article' => $product->article,
                            'title' => mb_substr($product->title ?? '', 0, 40),
                            'rozmir' => json_encode($raw['Rozmir'], JSON_UNESCAPED_UNICODE),
                        ];
                    }
                }

                if (!empty($raw['select'])) $withSelect++;
                if (!empty($raw['characteristics'])) $withCharacteristics++;
            }
        });

        $this->table(['Поле в raw', 'Кількість товарів'], [
            ['Rozmir (top-level)', $withRozmir],
            ['select (array)', $withSelect],
            ['characteristics (array)', $withCharacteristics],
        ]);

        $this->newLine();

        // Приклади Rozmir
        if (!empty($rozmirExamples)) {
            $this->info("📐 Приклади товарів з Rozmir:");
            $this->table(['Article', 'Title', 'Rozmir'], $rozmirExamples);
        } else {
            $this->warn("⚠️  Немає товарів з полем Rozmir в raw!");
            $this->newLine();
            $this->line("   Можливо потрібно пересинхронізувати товари з Horoshop:");
            $this->line("   php artisan horoshop:sync");
        }

        $this->newLine();

        // Приклади товарів з size
        $sample = (int) $this->option('sample');
        if ($withSize > 0) {
            $this->info("✅ Приклади товарів з size:");
            $examples = Product::whereNotNull('size')
                ->where('size', '!=', '')
                ->take($sample)
                ->get(['article', 'title', 'size']);
            
            $rows = $examples->map(fn($p) => [
                $p->article,
                mb_substr($p->title, 0, 50),
                $p->size,
            ])->toArray();

            $this->table(['Article', 'Title', 'Size'], $rows);
        }

        // Приклади raw ключів
        $this->newLine();
        $this->info("🔑 Приклад ключів raw (перший товар):");
        $first = Product::whereNotNull('raw')->first();
        if ($first && is_array($first->raw)) {
            $keys = array_keys($first->raw);
            $this->line("   " . implode(', ', $keys));
        }

        return 0;
    }
}
