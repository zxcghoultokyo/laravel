<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Завантажує товари з експорту production API
     * Файл: database/seeds/products-export.json
     *
     * Запуск: php artisan db:seed --class=ProductsSeeder
     */
    public function run(): void
    {
        $file = database_path('seeds/products-export.json');
        
        if (!file_exists($file)) {
            $this->command->warn("File not found: $file");
            $this->command->info("Download with: curl -s 'https://aimbot.laravel.cloud/api/debug/products?limit=2321' > $file");
            return;
        }

        $data = json_decode(file_get_contents($file), true);
        
        if (!isset($data['sample'])) {
            $this->command->error("Invalid format: expected 'sample' key in JSON");
            return;
        }

        $products = $data['sample'];
        $this->command->info("Found " . count($products) . " products to insert");

        // Insert in chunks to avoid memory issues
        $chunks = array_chunk($products, 50);
        
        foreach ($chunks as $chunk) {
            foreach ($chunk as $product) {
                Product::updateOrCreate(
                    ['article' => $product['article']],
                    [
                        'id'                    => $product['id'] ?? null,
                        'parent_article'        => $product['parent_article'] ?? $product['article'],
                        'title'                 => $product['title'],
                        'title_json'            => $product['title_json'] ?? ['ua' => $product['title']],
                        'price'                 => floatval($product['price'] ?? 0),
                        'price_old'             => floatval($product['price_old'] ?? 0),
                        'category_path'         => $product['category_path'] ?? '',
                        'slug'                  => $product['slug'] ?? '',
                        'link'                  => $product['link'] ?? '',
                        'images'                => $product['images'] ?? [],
                        'raw'                   => $product['raw'] ?? [],
                        'search_index'          => $product['search_index'] ?? '',
                        'orders_count'          => intval($product['orders_count'] ?? 0),
                        'views_count'           => intval($product['views_count'] ?? 0),
                        'added_to_cart_count'   => intval($product['added_to_cart_count'] ?? 0),
                        'in_stock'              => boolval($product['in_stock'] ?? true),
                        'presence'              => $product['presence'] ?? 'В наявності',
                        'quantity'              => intval($product['quantity'] ?? 0),
                        'popularity'            => intval($product['popularity'] ?? 0),
                        'we_recommended'        => boolval($product['we_recommended'] ?? false),
                        'color'                 => $product['color'] ?? null,
                        'brand'                 => $product['brand'] ?? null,
                        'display_in_showcase'   => boolval($product['display_in_showcase'] ?? false),
                    ]
                );
            }
            
            $this->command->info("Inserted " . count($chunk) . " products...");
        }

        $this->command->info("✅ Seeding complete! Total products: " . Product::count());
    }
}
