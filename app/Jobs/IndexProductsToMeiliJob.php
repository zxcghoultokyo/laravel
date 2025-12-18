<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\Search\MeiliClient;
use App\Support\ProductRawExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class IndexProductsToMeiliJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Backward-compat: старі job-и могли серіалізувати "chunk".
     */
    public int $chunkSize = 500;

    public ?int $chunk = null;

    public function __construct(int $chunkSize = 500)
    {
        $this->chunkSize = max(50, (int) $chunkSize);
        $this->onQueue('meili');
    }

    protected function effectiveChunkSize(): int
    {
        // якщо прийшла стара job з "chunk"
        $size = $this->chunk ?? $this->chunkSize;

        return max(50, (int) $size);
    }

    public function handle(MeiliClient $meili): void
    {
        $index = $meili->productsIndex();
        $chunkSize = $this->effectiveChunkSize();

        // Ensure filterable attributes for AI flags exist (idempotent)
        try {
            $index->updateSettings([
                'filterableAttributes' => array_values(array_unique([
                    'has_ai_type', 'has_ai_category', 'brand', 'color', 'in_stock', 'display_in_showcase',
                ])),
                'searchableAttributes' => [
                    'title',
                    'search_index',
                    'description',
                    'attributes_text',
                    'category_path',
                    'brand',
                    'color',
                ],
            ]);
        } catch (\Throwable $e) {
            // non-fatal: settings update may be async or already set
        }

        Product::query()
            ->with('aiIndex')
            ->orderBy('id')
            ->chunk($chunkSize, function ($products) use ($index) {
                $docs = [];

                // Підтягнемо raw батьківських товарів для fallback
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

                        'in_stock' => (int) ((bool) $p->in_stock),
                        'display_in_showcase' => (int) ((bool) $p->display_in_showcase),
                        'quantity' => (int) ($p->quantity ?? 0),
                        'presence_raw' => (string) ($p->presence ?? ''),

                        // ⚠️ В products price/price_old decimal(10,2) — як int ти втрачаєш копійки.
                        // Якщо ок — залишай. Якщо ні:
                        'price' => (float) ($p->price ?? 0),
                        'price_old' => (float) ($p->price_old ?? 0),

                        'we_recommended' => (int) ((bool) $p->we_recommended),
                        'popularity' => (int) ($p->popularity ?? 0),
                        'orders_count' => (int) ($p->orders_count ?? 0),
                        'views_count' => (int) ($p->views_count ?? 0),
                        'added_to_cart_count' => (int) ($p->added_to_cart_count ?? 0),

                        // ✅ замість неіснуючого updated_at_ts
                        'updated_at_ts' => $p->updated_at ? $p->updated_at->getTimestamp() : 0,

                        'ai_product_type' => is_string($p->aiIndex->product_type ?? null) ? trim($p->aiIndex->product_type) : '',
                        'ai_category' => is_string($p->aiIndex->ai_category ?? null) ? trim($p->aiIndex->ai_category) : '',
                        'has_ai_type' => ($p->aiIndex->product_type ?? null) ? 1 : 0,
                        'has_ai_category' => ($p->aiIndex->ai_category ?? null) ? 1 : 0,
                    ];
                }

                if (! empty($docs)) {
                    $index->addDocuments($docs);
                }
            });
    }
}
