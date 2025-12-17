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

    public function __construct(public int $chunk = 500) {}

    public function handle(MeiliClient $meili): void
    {
        // без fallback: якщо Meili не доступний — хай впаде (щоб ти бачив проблему)
        $index = $meili->productsIndex();

        Product::query()
            ->with('aiIndex')
            ->orderBy('id')
            ->chunkById($this->chunk, function (EloquentCollection $products) use ($index) {

                $docs = $products->map(function (Product $p) {
                    $ai = $p->aiIndex;

                    return [
                        'id' => (int) $p->id,

                        // searchable
                        'title' => (string) ($p->title ?? ''),
                        'search_index' => (string) ($p->search_index ?? ''),
                        'category_path' => (string) ($p->category_path ?? ''),
                        'brand' => (string) ($p->brand ?? ''),
                        'color' => (string) ($p->color ?? ''),

                        // filterable
                        'camo_group' => $this->normalizeCamo(
                            (string) ($p->color ?? ''),
                            (string) ($p->search_index ?? ''),
                            (string) ($p->title ?? ''),
                            (string) ($p->category_path ?? '')
                        ),
                        'product_type' => (string) ($ai->product_type ?? ''),
                        'ai_category'  => (string) ($ai->ai_category ?? ''),

                        // numeric/filter
                        'price' => (float) ($p->price ?? 0),
                        'in_stock' => (bool) ($p->in_stock ?? false),

                        // ranking/sort
                        'we_recommended' => (bool) ($p->we_recommended ?? false),
                        'popularity' => (int) ($p->popularity ?? 0),
                        'orders_count' => (int) ($p->orders_count ?? 0),
                        'views_count' => (int) ($p->views_count ?? 0),
                        'added_to_cart_count' => (int) ($p->added_to_cart_count ?? 0),
                        'updated_at_ts' => $p->updated_at ? $p->updated_at->timestamp : 0,
                    ];
                })->values()->all();

                $index->addDocuments($docs, 'id');
            });
    }

    private function normalizeCamo(string $color, string $searchIndex, string $title, string $categoryPath): ?string
    {
        $t = mb_strtolower($color.' '.$searchIndex.' '.$title.' '.$categoryPath);

        if (
            str_contains($t, 'mm14') ||
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
