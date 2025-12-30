<?php

namespace App\Console\Commands;

use App\Services\Horoshop\HoroshopClient;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

class SyncHoroshopCommand extends Command
{
    protected $signature = 'horoshop:sync 
                            {--limit=200 : Кількість товарів за один батч}
                            {--max=0 : Максимум товарів (0 = всі)}
                            {--dry-run : Тільки показати що буде синхронізовано}';

    protected $description = 'Синхронізація товарів з Horoshop з детальним логуванням';

    public function handle(HoroshopClient $client): int
    {
        $limit = (int) $this->option('limit');
        $maxProducts = (int) $this->option('max');
        $dryRun = $this->option('dry-run');

        $this->info("🚀 Старт синхронізації Horoshop");
        $this->info("   Батч: {$limit} товарів");
        $this->info("   Максимум: " . ($maxProducts > 0 ? $maxProducts : 'всі'));
        $this->info("   Dry-run: " . ($dryRun ? 'так' : 'ні'));
        $this->newLine();

        $offset = 0;
        $totalProcessed = 0;
        $totalCreated = 0;
        $totalUpdated = 0;
        $totalWithSize = 0;
        $batchNumber = 0;

        do {
            $batchNumber++;
            $this->line("📦 Батч #{$batchNumber} (offset: {$offset})...");

            $payload = [
                'expr' => [
                    'display_in_showcase' => 1,
                ],
                'limit' => $limit,
                'offset' => $offset,
                'includedParams' => [
                    'title', 'article', 'parent_article', 'price', 'price_old',
                    'parent', 'images', 'slug', 'link', 'presence', 'quantity',
                    'display_in_showcase', 'popularity', 'color', 'brand',
                    'description', 'characteristics', 'short_description',
                    'select', 'params', 'mod_title', 'Rozmir', 'Kolir', 'Dovzhina',
                    'seo_title', 'seo_keywords', 'seo_description',
                    'we_recommended', 'icons',
                ],
            ];

            try {
                $response = $client->request('catalog/export', $payload);
            } catch (\Exception $e) {
                $this->error("❌ Помилка API: " . $e->getMessage());
                return 1;
            }

            $products = Arr::get($response, 'products', []);
            $count = count($products);

            if (empty($products)) {
                $this->info("   ✅ Більше товарів немає");
                break;
            }

            $this->line("   Отримано: {$count} товарів");

            $batchWithSize = 0;
            $batchCreated = 0;
            $batchUpdated = 0;

            foreach ($products as $item) {
                $article = $item['article'] ?? null;
                if (!$article) continue;

                $title = $item['title']['ua'] ?? $item['title']['ru'] ?? '';
                $size = $this->extractSizeFromItem($item, $title);

                if (!$dryRun) {
                    $exists = Product::where('article', $article)->exists();
                    
                    $product = Product::firstOrNew(['article' => $article]);
                    
                    $brand = Arr::get($item, 'brand.value.ua')
                        ?? Arr::get($item, 'brand.value.ru')
                        ?? null;

                    $product->fill([
                        'article' => $article,
                        'parent_article' => $item['parent_article'] ?? null,
                        'title' => $title,
                        'title_json' => $item['title'] ?? null,
                        'price' => $item['price'] ?? 0,
                        'price_old' => $item['price_old'] ?? 0,
                        'category_path' => $item['parent']['value'] ?? null,
                        'slug' => $item['slug'] ?? null,
                        'link' => $item['link'] ?? null,
                        'images' => $item['images'] ?? [],
                        'raw' => $item,
                        'presence' => Arr::get($item, 'presence.value.ua')
                            ?? Arr::get($item, 'presence.value.ru')
                            ?? null,
                        'quantity' => $item['quantity'] ?? 0,
                        'brand' => $brand,
                        'popularity' => $item['popularity'] ?? 0,
                        'we_recommended' => (bool) ($item['we_recommended'] ?? false),
                        'display_in_showcase' => (bool) ($item['display_in_showcase'] ?? false),
                        'in_stock' => $this->isInStock($item),
                        'color' => Arr::get($item, 'color.value.ua')
                            ?? Arr::get($item, 'color.value.ru')
                            ?? null,
                        'size' => $size,
                    ]);

                    // Build search_index for new products
                    $product->search_index = $this->buildSearchIndex($item, $product);
                    $product->save();

                    if ($exists) {
                        $batchUpdated++;
                    } else {
                        $batchCreated++;
                    }
                }

                if ($size) {
                    $batchWithSize++;
                    if ($this->output->isVerbose()) {
                        $this->line("      📐 {$article}: size=\"{$size}\" ({$title})");
                    }
                }

                $totalProcessed++;

                if ($maxProducts > 0 && $totalProcessed >= $maxProducts) {
                    break 2;
                }
            }

            $totalCreated += $batchCreated;
            $totalUpdated += $batchUpdated;
            $totalWithSize += $batchWithSize;

            $this->info("   ✓ Оброблено: created={$batchCreated}, updated={$batchUpdated}, with_size={$batchWithSize}");

            $offset += $limit;

        } while (true);

        $this->newLine();
        $this->info("═══════════════════════════════════════");
        $this->info("📊 ПІДСУМОК:");
        $this->info("   Всього оброблено: {$totalProcessed}");
        $this->info("   Створено нових:   {$totalCreated}");
        $this->info("   Оновлено:         {$totalUpdated}");
        $this->info("   З розміром:       {$totalWithSize}");
        $this->info("═══════════════════════════════════════");

        // Перевірка БД
        $dbWithSize = Product::whereNotNull('size')->where('size', '!=', '')->count();
        $this->newLine();
        $this->info("🔍 В базі товарів з size: {$dbWithSize}");

        return 0;
    }

    /**
     * Extract size from multiple sources
     */
    protected function extractSizeFromItem(array $item, ?string $title): ?string
    {
        // 1. Try Rozmir at top level (Horoshop custom attribute - most common!)
        if (!empty($item['Rozmir']['value'])) {
            $rozmir = $item['Rozmir']['value'];
            $size = is_array($rozmir) 
                ? ($rozmir['ua'] ?? $rozmir['ru'] ?? reset($rozmir))
                : $rozmir;
            if (is_string($size) && trim($size) !== '') {
                return trim($size);
            }
        }

        // 2. Try raw.Rozmir (when raw is nested)
        if (!empty($item['raw']['Rozmir']['value'])) {
            $rozmir = $item['raw']['Rozmir']['value'];
            $size = is_array($rozmir) 
                ? ($rozmir['ua'] ?? $rozmir['ru'] ?? reset($rozmir))
                : $rozmir;
            if (is_string($size) && trim($size) !== '') {
                return trim($size);
            }
        }

        // 3. Try select array (characteristics with type='select')
        foreach ($item['select'] ?? [] as $sel) {
            $name = mb_strtolower($sel['name']['ua'] ?? $sel['name']['ru'] ?? '');
            if (in_array($name, ['розмір', 'размер', 'size'])) {
                $val = $sel['value']['ua'] ?? $sel['value']['ru'] ?? null;
                if ($val) return $val;
            }
        }

        // 4. Try characteristics array
        foreach ($item['characteristics'] ?? [] as $char) {
            $name = mb_strtolower($char['name']['ua'] ?? $char['name']['ru'] ?? '');
            if (in_array($name, ['розмір', 'размер', 'size'])) {
                $val = $char['value']['ua'] ?? $char['value']['ru'] ?? null;
                if ($val) return $val;
            }
        }

        // 5. Try params array
        foreach ($item['params'] ?? [] as $param) {
            $name = mb_strtolower($param['name']['ua'] ?? $param['name']['ru'] ?? '');
            if (in_array($name, ['розмір', 'размер', 'size'])) {
                $val = $param['value']['ua'] ?? $param['value']['ru'] ?? null;
                if ($val) return $val;
            }
        }

        // 6. Try to extract from title (e.g., "Jacket XL" or "Штани M/Regular")
        if ($title && preg_match('/\b(XXS|XS|S|M|L|XL|XXL|XXXL|2XL|3XL|4XL|5XL)(\/\w+)?\b/i', $title, $m)) {
            return $m[0];
        }

        return null;
    }

    protected function isInStock(array $item): bool
    {
        $presence = Arr::get($item, 'presence.value.ua')
            ?? Arr::get($item, 'presence.value.ru')
            ?? '';

        $presenceLower = mb_strtolower($presence);

        if (str_contains($presenceLower, 'немає') || str_contains($presenceLower, 'нет')) {
            return false;
        }

        $qty = (int) ($item['quantity'] ?? 0);
        if ($qty > 0) return true;

        if (str_contains($presenceLower, 'є в наявності') || str_contains($presenceLower, 'в наличии')) {
            return true;
        }

        return false;
    }

    /**
     * Build search_index string for LIKE searches
     */
    protected function buildSearchIndex(array $item, Product $product): string
    {
        $parts = [];

        $parts[] = Arr::get($item, 'title.ua', '');
        $parts[] = Arr::get($item, 'title.ru', '');
        $parts[] = Arr::get($item, 'parent.value', '');
        $parts[] = Arr::get($item, 'brand.value.ua', '');
        $parts[] = Arr::get($item, 'brand.value.ru', '');
        $parts[] = Arr::get($item, 'color.value.ua', '');
        $parts[] = Arr::get($item, 'color.value.ru', '');
        $parts[] = $item['article'] ?? '';
        $parts[] = $item['parent_article'] ?? '';

        // Description (strip HTML)
        $descUa = strip_tags(Arr::get($item, 'description.ua', ''));
        $descRu = strip_tags(Arr::get($item, 'description.ru', ''));
        $parts[] = mb_substr($descUa, 0, 500);
        $parts[] = mb_substr($descRu, 0, 500);

        // Characteristics
        foreach ($item['characteristics'] ?? [] as $char) {
            $parts[] = Arr::get($char, 'name.ua', '');
            $parts[] = Arr::get($char, 'value.ua', '');
        }

        // Select attributes
        foreach ($item['select'] ?? [] as $sel) {
            $parts[] = Arr::get($sel, 'name.ua', '');
            $parts[] = Arr::get($sel, 'value.ua', '');
        }

        // Size
        if ($product->size) {
            $parts[] = $product->size;
        }

        return mb_strtolower(implode(' ', array_filter($parts)));
    }
}
