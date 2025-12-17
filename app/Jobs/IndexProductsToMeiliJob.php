<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\Search\MeiliClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class IndexProductsToMeiliJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $fromId,
        public int $toId,
        public int $chunk = 500,
    ) {
    }

    public function handle(MeiliClient $meili): void
    {
        $index = $meili->index('products');

        Product::query()
            ->with('aiIndex')
            ->whereBetween('id', [$this->fromId, $this->toId])
            ->orderBy('id')
            ->chunkById($this->chunk, function (EloquentCollection $products) use ($index) {

                $docs = $products->map(function (Product $p) {
                    $ai = $p->aiIndex;

                    return [
                        'id' => (int) $p->id,

                        // searchable
                        'title' => (string) ($p->title ?? ''),
                        'category_path' => (string) ($p->category_path ?? ''),
                        'search_index' => (string) ($p->search_index ?? ''),

                        'ai_category' => (string) ($ai->ai_category ?? ''),
                        'ai_product_type' => (string) ($ai->product_type ?? ''),
                        'ai_materials' => (string) ($ai->materials ?? ''),
                        'ai_standards' => (string) ($ai->standards ?? ''),
                        'ai_keywords' => (string) ($ai->keywords ?? ''),
                        'ai_slang' => (string) ($ai->slang ?? ''),
                        'ai_spec' => (string) ($ai->spec ?? ''),

                        // filters / facets
                        'price' => (float) ($p->price ?? 0),
                        'price_old' => (float) ($p->price_old ?? 0),
                        'in_stock' => (int) ($p->in_stock ?? 0),
                        'presence_raw' => (string) ($p->presence ?? ''),
                        'camo_group' => $this->detectCamoGroup((string) ($p->title ?? ''), (string) ($p->category_path ?? ''), (string) ($ai->keywords ?? '')),

                        // ranking
                        'we_recommended' => (int) ($p->we_recommended ?? 0),
                        'popularity' => (float) ($p->popularity ?? 0),
                        'orders_count' => (int) ($p->orders_count ?? 0),
                        'views_count' => (int) ($p->views_count ?? 0),
                        'added_to_cart_count' => (int) ($p->added_to_cart_count ?? 0),
                        'updated_at_ts' => $p->updated_at ? $p->updated_at->timestamp : 0,
                    ];
                })->values()->all();

                if (!empty($docs)) {
                    $index->addDocuments($docs);
                }
            });
    }

    private function detectCamoGroup(string ...$parts): ?string
    {
        $t = mb_strtolower(implode(' ', $parts));

        if (
            str_contains($t, 'мм-14') ||
            str_contains($t, 'мм14') ||
            str_contains($t, 'піксель') ||
            str_contains($t, 'пиксель')
        ) return 'mm14_pixel';

        if (str_contains($t, 'multicam') || str_contains($t, 'мультикам') || preg_match('/\bmc\b/u', $t)) return 'multicam';
        if (str_contains($t, 'coyote')   || str_contains($t, 'койот')) return 'coyote';
        if (str_contains($t, 'black')    || str_contains($t, 'чорн') || str_contains($t, 'черн')) return 'black';
        if (str_contains($t, 'oliv')     || str_contains($t, 'олив') || str_contains($t, 'olive') || preg_match('/\bod\b/u', $t)) return 'olive';

        return null;
    }
}
