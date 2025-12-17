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

    public int $timeout = 120;

    public function __construct(public int $chunk = 500) {}

    public function handle(MeiliClient $meili): void
    {
        $index = $meili->productsIndex();

        Product::query()
            ->with('aiIndex')
            ->orderBy('id')
            ->chunkById($this->chunk, function (EloquentCollection $products) use ($index) {

                $docs = $products->map(function (Product $p) {
                    $ai = $p->aiIndex;

                    // IMPORTANT: normalize flags to 0/1 so filters are stable
                    $display = (int) ((int)($p->display_in_showcase ?? 0) === 1);
                    $inStock = (int) ((int)($p->in_stock ?? 0) === 1);

                    // Presence can exist even if stock accounting differs
                    $presenceRaw = (string) ($p->presence ?? $p->presence_raw ?? '');

                    return [
                        'id' => (int) $p->id,

                        // searchable
                        'title' => (string) ($p->title ?? ''),
                        'search_index' => (string) ($p->search_index ?? ''),
                        'category_path' => (string) ($p->category_path ?? ''),
                        'brand' => (string) ($p->brand ?? ''),
                        'color' => (string) ($p->color ?? ''),

                        // filterable flags (0/1)
                        'display_in_showcase' => $display,
                        'in_stock' => $inStock,
                        'presence_raw' => $presenceRaw,

                        // numeric
                        'price' => (float) ($p->price ?? 0),
                        'price_old' => (float) ($p->price_old ?? 0),
                        'quantity' => (int) ($p->quantity ?? 0),

                        // type facet
                        'product_type' => (string) ($ai->product_type ?? ''),
                        'ai_category'  => (string) ($ai->ai_category ?? ''),

                        // business sort
                        'we_recommended' => (int) ((int)($p->we_recommended ?? 0) === 1),
                        'popularity' => (int) ($p->popularity ?? 0),
                        'orders_count' => (int) ($p->orders_count ?? 0),
                        'views_count' => (int) ($p->views_count ?? 0),
                        'added_to_cart_count' => (int) ($p->added_to_cart_count ?? 0),
                        'updated_at_ts' => $p->updated_at ? $p->updated_at->timestamp : 0,
                    ];
                })->values()->all();

                $index->addDocuments($docs);
            });
    }
}
