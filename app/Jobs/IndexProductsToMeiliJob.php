<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\Search\MeiliClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class IndexProductsToMeiliJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $chunkSize;

    public function __construct(int $chunkSize = 500)
    {
        $this->chunkSize = max(50, $chunkSize);
        $this->onQueue('meili');
    }

    public function handle(MeiliClient $meili): void
    {
        $index = $meili->productsIndex();

        Product::query()
            ->with('aiIndex')
            ->orderBy('id')
            ->chunk($this->chunkSize, function ($products) use ($index) {
                $docs = [];

                foreach ($products as $p) {
                    $docs[] = [
                        'id' => (int) $p->id,

                        // для дедупу варіантів/розмірів
                        'article'        => (string) ($p->article ?? ''),
                        'parent_article' => (string) ($p->parent_article ?? ''),

                        // текст
                        'title'        => (string) ($p->title ?? ''),
                        'category_path'=> (string) ($p->category_path ?? ''),
                        'brand'        => (string) ($p->brand ?? ''),
                        'color'        => (string) ($p->color ?? ''),

                        // “широкий” індексний рядок (синоніми/категорії/опис)
                        'search_index' => (string) ($p->search_index ?? ''),

                        // сток/вітрина
                        'in_stock'            => (int) ((bool) $p->in_stock),
                        'display_in_showcase' => (int) ((bool) $p->display_in_showcase),
                        'quantity'            => (int) ($p->quantity ?? 0),
                        'presence_raw'        => (string) ($p->presence ?? ''),

                        // ціни
                        'price'     => (int) ($p->price ?? 0),
                        'price_old' => (int) ($p->price_old ?? 0),

                        // бізнес-сигнали
                        'we_recommended'       => (int) ((bool) $p->we_recommended),
                        'popularity'           => (int) ($p->popularity ?? 0),
                        'orders_count'         => (int) ($p->orders_count ?? 0),
                        'views_count'          => (int) ($p->views_count ?? 0),
                        'added_to_cart_count'  => (int) ($p->added_to_cart_count ?? 0),
                        'updated_at_ts'        => (int) ($p->updated_at_ts ?? 0),

                        // AI класифікація (для “плити” != “бронежилет”)
                        'ai_product_type' => (string) (($p->aiIndex->product_type ?? '') ?: ''),
                        'ai_category'     => (string) (($p->aiIndex->ai_category ?? '') ?: ''),
                        'camo_group'      => (string) ($p->camo_group ?? ''),
                        'category_id'     => (int) ($p->category_id ?? 0),
                    ];
                }

                if (!empty($docs)) {
                    $index->addDocuments($docs);
                }
            });
    }
}
