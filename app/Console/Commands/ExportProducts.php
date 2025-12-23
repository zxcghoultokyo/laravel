<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;

class ExportProducts extends Command
{
    protected $signature = 'products:export {--limit=5000}';
    protected $description = 'Export products to JSON for seeding';

    public function handle()
    {
        $limit = (int) $this->option('limit');
        
        $products = Product::limit($limit)->get();
        
        $data = [
            'total' => Product::count(),
            'exported' => $products->count(),
            'products' => $products->map(fn($p) => [
                'id'                  => $p->id,
                'article'             => $p->article,
                'parent_article'      => $p->parent_article,
                'title'               => $p->title,
                'title_json'          => $p->title_json,
                'price'               => $p->price,
                'price_old'           => $p->price_old,
                'category_path'       => $p->category_path,
                'slug'                => $p->slug,
                'link'                => $p->link,
                'images'              => $p->images,
                'raw'                 => $p->raw,
                'search_index'        => $p->search_index,
                'orders_count'        => $p->orders_count,
                'views_count'         => $p->views_count,
                'added_to_cart_count' => $p->added_to_cart_count,
                'in_stock'            => $p->in_stock,
                'presence'            => $p->presence,
                'quantity'            => $p->quantity,
                'popularity'          => $p->popularity,
                'we_recommended'      => $p->we_recommended,
                'color'               => $p->color,
                'brand'               => $p->brand,
                'display_in_showcase' => $p->display_in_showcase,
            ])->toArray(),
        ];
        
        $file = storage_path('products-full.json');
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        $this->info("✅ Exported to: $file");
        $this->info("Total: {$data['total']}, Exported: {$data['exported']}");
    }
}
