<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Search\MeiliClient;
use App\Support\ProductRawExtractor;
use Illuminate\Console\Command;

class MeiliReindexProductsSync extends Command
{
    protected $signature = 'meili:reindex-products-sync {--limit=100 : Max products to reindex}';
    protected $description = 'Синхронна переіндексація товарів (для тестування)';

    public function __construct(private MeiliClient $meiliClient)
    {
        parent::__construct();
    }

    public function handle()
    {
        $limit = (int) $this->option('limit');
        
        $this->info("=== Синхронна переіндексація Meilisearch ===");
        $this->newLine();

        $index = $this->meiliClient->productsIndex();
        
        // Отримуємо продукти
        $query = Product::query()
            ->with('aiIndex')
            ->orderBy('id');
        
        if ($limit > 0) {
            $query->limit($limit);
            $this->line("Обмеження: {$limit} товарів");
        }
        
        $products = $query->get();
        $total = $products->count();
        
        if ($total === 0) {
            $this->warn('Немає товарів для індексації');
            return 0;
        }
        
        $this->line("Знайдено товарів: {$total}");
        $this->newLine();
        
        // Підготовка parent_raw
        $parentArticles = $products->pluck('parent_article')->filter()->unique()->all();
        $parentRawMap = [];
        
        if ($parentArticles) {
            Product::query()
                ->whereIn('article', $parentArticles)
                ->get(['article', 'raw'])
                ->each(function ($parent) use (&$parentRawMap) {
                    $parentRawMap[$parent->article] = is_array($parent->raw ?? null) ? $parent->raw : (array) ($parent->raw ?? []);
                });
        }
        
        // Формуємо документи
        $docs = [];
        $bar = $this->output->createProgressBar($total);
        $bar->start();
        
        foreach ($products as $p) {
            $lang = 'ua';
            $raw = is_array($p->raw ?? null) ? $p->raw : (array) ($p->raw ?? []);
            $parentRaw = [];
            $parentArticle = (string) ($p->parent_article ?? '');
            
            if ($parentArticle !== '' && isset($parentRawMap[$parentArticle])) {
                $parentRaw = $parentRawMap[$parentArticle];
            }

            $desc = ProductRawExtractor::description($raw, $lang, $parentRaw);
            $attrsText = ProductRawExtractor::attributesText($raw, $lang, $parentRaw);
            $attrsMap = ProductRawExtractor::attributes($raw, $lang, $parentRaw);

            $docs[] = [
                'id' => (int) $p->id,
                'article' => (string) ($p->article ?? ''),
                'parent_article' => (string) ($p->parent_article ?? ''),
                'title' => (string) ($p->title ?? ''),
                'category_path' => (string) ($p->category_path ?? ''),
                'brand' => (string) ($p->brand ?? ''),
                'color' => (string) ($p->color ?? ''),
                'search_index' => (string) ($p->search_index ?? ''),
                'description' => $desc,
                'attributes_text' => $attrsText,
                'attrs' => $attrsMap,
                
                // ✅ Правильні boolean типи
                'in_stock' => (bool) $p->in_stock,
                'display_in_showcase' => (bool) $p->display_in_showcase,
                'we_recommended' => (bool) $p->we_recommended,
                
                'quantity' => (int) ($p->quantity ?? 0),
                'presence_raw' => (string) ($p->presence ?? ''),
                'price' => (float) ($p->price ?? 0),
                'price_old' => (float) ($p->price_old ?? 0),
                'popularity' => (int) ($p->popularity ?? 0),
                'orders_count' => (int) ($p->orders_count ?? 0),
                'views_count' => (int) ($p->views_count ?? 0),
                'added_to_cart_count' => (int) ($p->added_to_cart_count ?? 0),
                'updated_at_ts' => $p->updated_at ? $p->updated_at->getTimestamp() : 0,
                
                'ai_product_type' => is_string($p->aiIndex->product_type ?? null) ? trim($p->aiIndex->product_type) : '',
                'ai_category' => is_string($p->aiIndex->ai_category ?? null) ? trim($p->aiIndex->ai_category) : '',
                'has_ai_type' => (bool) ($p->aiIndex->product_type ?? null),
                'has_ai_category' => (bool) ($p->aiIndex->ai_category ?? null),
            ];
            
            $bar->advance();
        }
        
        $bar->finish();
        $this->newLine();
        $this->newLine();
        
        // Відправляємо в Meilisearch
        $this->info("📤 Відправка в Meilisearch...");
        
        try {
            $result = $index->addDocuments($docs);
            $taskId = $result['taskUid'] ?? null;
            
            $this->info("✓ Документи відправлено. Task ID: {$taskId}");
            $this->line("Індексується асинхронно на стороні Meilisearch...");
            $this->newLine();
            
            // Приклади проіндексованих товарів
            $inStockCount = count(array_filter($docs, fn($d) => $d['in_stock'] === true));
            $notInStockCount = count(array_filter($docs, fn($d) => $d['in_stock'] === false));
            
            $this->info("📊 Статистика:");
            $this->line("  В наявності (in_stock=true): {$inStockCount}");
            $this->line("  Немає в наявності (in_stock=false): {$notInStockCount}");
            $this->newLine();
            
            // Приклади товарів в наявності
            if ($inStockCount > 0) {
                $this->line("✓ Приклади товарів в наявності:");
                $examples = array_filter($docs, fn($d) => $d['in_stock'] === true);
                foreach (array_slice($examples, 0, 3) as $doc) {
                    $this->line("  - [{$doc['article']}] {$doc['title']}");
                }
            }
            
            $this->newLine();
            $this->info("⏳ Зачекайте 5-10 секунд щоб Meilisearch завершив індексацію");
            $this->line("Потім перевірте: php artisan test:meili-direct \"сумка\"");
            
        } catch (\Exception $e) {
            $this->error("✗ Помилка: " . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
