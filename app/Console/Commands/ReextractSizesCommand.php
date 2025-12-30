<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

class ReextractSizesCommand extends Command
{
    protected $signature = 'products:reextract-sizes 
                            {--limit=0 : Максимум товарів (0 = всі)}
                            {--dry-run : Тільки показати що буде оновлено}';

    protected $description = 'Переекстрактити розміри з raw поля для існуючих товарів';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $dryRun = $this->option('dry-run');

        $this->info("🔄 Re-extract sizes з raw поля");
        $this->info("   Limit: " . ($limit > 0 ? $limit : 'всі'));
        $this->info("   Dry-run: " . ($dryRun ? 'так' : 'ні'));
        $this->newLine();

        $query = Product::whereNotNull('raw');
        $total = $query->count();

        $this->info("📊 Всього товарів з raw: {$total}");
        $this->newLine();

        if ($limit > 0) {
            $query->limit($limit);
        }

        $processed = 0;
        $withSize = 0;
        $updated = 0;
        $skipped = 0;

        $bar = $this->output->createProgressBar($limit > 0 ? min($limit, $total) : $total);
        $bar->start();

        $query->chunk(500, function ($products) use (&$processed, &$withSize, &$updated, &$skipped, $dryRun, $bar) {
            foreach ($products as $product) {
                $raw = $product->raw;
                if (!is_array($raw)) {
                    $skipped++;
                    $bar->advance();
                    continue;
                }

                $title = $product->title;
                $newSize = $this->extractSizeFromRaw($raw, $title);

                if ($newSize) {
                    $withSize++;
                    
                    if ($product->size !== $newSize) {
                        if (!$dryRun) {
                            $product->size = $newSize;
                            $product->save();
                        }
                        $updated++;

                        if ($this->output->isVerbose()) {
                            $oldSize = $product->getOriginal('size') ?? 'null';
                            $this->newLine();
                            $this->line("   📐 {$product->article}: \"{$oldSize}\" → \"{$newSize}\"");
                        }
                    }
                }

                $processed++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->info("═══════════════════════════════════════");
        $this->info("📊 ПІДСУМОК:");
        $this->info("   Оброблено:     {$processed}");
        $this->info("   Пропущено:     {$skipped}");
        $this->info("   Знайдено size: {$withSize}");
        $this->info("   Оновлено:      {$updated}");
        $this->info("═══════════════════════════════════════");

        // Поточний стан БД
        $dbWithSize = Product::whereNotNull('size')->where('size', '!=', '')->count();
        $this->newLine();
        $this->info("🔍 В базі товарів з size: {$dbWithSize}");

        return 0;
    }

    protected function extractSizeFromRaw(array $raw, ?string $title): ?string
    {
        // 1. Try Rozmir at top level (Horoshop custom attribute - НАЙЧАСТІШЕ!)
        if (!empty($raw['Rozmir']['value'])) {
            $rozmir = $raw['Rozmir']['value'];
            $size = is_array($rozmir) 
                ? ($rozmir['ua'] ?? $rozmir['ru'] ?? reset($rozmir))
                : $rozmir;
            if (is_string($size) && trim($size) !== '') {
                return trim($size);
            }
        }

        // 2. Try select array
        foreach ($raw['select'] ?? [] as $sel) {
            $name = mb_strtolower($sel['name']['ua'] ?? $sel['name']['ru'] ?? '');
            if (in_array($name, ['розмір', 'размер', 'size'])) {
                $val = $sel['value']['ua'] ?? $sel['value']['ru'] ?? null;
                if ($val) return $val;
            }
        }

        // 3. Try characteristics array
        foreach ($raw['characteristics'] ?? [] as $char) {
            $name = mb_strtolower($char['name']['ua'] ?? $char['name']['ru'] ?? '');
            if (in_array($name, ['розмір', 'размер', 'size'])) {
                $val = $char['value']['ua'] ?? $char['value']['ru'] ?? null;
                if ($val) return $val;
            }
        }

        // 4. Try params array
        foreach ($raw['params'] ?? [] as $param) {
            $name = mb_strtolower($param['name']['ua'] ?? $param['name']['ru'] ?? '');
            if (in_array($name, ['розмір', 'размер', 'size'])) {
                $val = $param['value']['ua'] ?? $param['value']['ru'] ?? null;
                if ($val) return $val;
            }
        }

        // 5. Try to extract from title
        if ($title && preg_match('/\b(XXS|XS|S|M|L|XL|XXL|XXXL|2XL|3XL|4XL|5XL)(\/\w+)?\b/i', $title, $m)) {
            return $m[0];
        }

        return null;
    }
}
