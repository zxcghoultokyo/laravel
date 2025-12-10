<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SyncHoroshopProducts extends Command
{
    protected $signature = 'sync:horoshop-products {--chunk=100}';
    protected $description = 'Sync products from Horoshop catalog/export into local DB';

    public function handle(): int
    {
        $chunk = (int) $this->option('chunk') ?: 100;

        $baseUrl = config('services.horoshop.base_url'); // додамо в config/services.php
        $apiKey  = config('services.horoshop.api_key');

        if (! $baseUrl || ! $apiKey) {
            $this->error('Horoshop base_url or api_key is not configured.');
            return self::FAILURE;
        }

        $offset = 0;
        $totalImported = 0;

        $this->info("Starting Horoshop sync with chunk size: {$chunk}");

        while (true) {
            $this->info("Fetching products: offset={$offset}, limit={$chunk}");

            $response = Http::get("{$baseUrl}/catalog/export", [
                'offset' => $offset,
                'limit'  => $chunk,
                'key'    => $apiKey,
            ]);

            if ($response->failed()) {
                $this->error('Request to Horoshop failed: ' . $response->status());
                return self::FAILURE;
            }

            $data = $response->json();

            if (empty($data) || ! is_array($data)) {
                $this->warn('Empty response or invalid format, stopping.');
                break;
            }

            $batchCount = 0;

            foreach ($data as $item) {
                // Тут потрібно підлаштуватись під реальний формат Horoshop export
                $article   = $item['article'] ?? $item['sku'] ?? null;
                $titleUa   = $item['title_uk'] ?? $item['title'] ?? null;
                $titleJson = [
                    'uk' => $item['title_uk'] ?? null,
                    'ru' => $item['title_ru'] ?? null,
                    'en' => $item['title_en'] ?? null,
                ];

                $categoryPath = $item['category_path'] ?? null;
                $slug         = $item['slug'] ?? null;
                $link         = $item['url'] ?? null;

                $images = $item['images'] ?? [];
                if (! is_array($images)) {
                    $images = [$images];
                }

                $raw = $item;

                if (! $article) {
                    // якщо немає артикулу — пропускаємо
                    continue;
                }

                $searchIndex = $this->buildSearchIndex($titleUa, $categoryPath, $article);

                Product::updateOrCreate(
                    ['article' => $article],
                    [
                        'title'                 => $titleUa,
                        'title_json'            => $titleJson,
                        'price'                 => $item['price'] ?? null,
                        'price_old'             => $item['old_price'] ?? null,
                        'category_path'         => $categoryPath,
                        'slug'                  => $slug,
                        'link'                  => $link,
                        'images'                => $images,
                        'raw'                   => $raw,
                        'search_index'          => $searchIndex,
                    ]
                );

                $batchCount++;
                $totalImported++;
            }

            $this->info("Imported/updated in this batch: {$batchCount}");

            if ($batchCount < $chunk) {
                // менше ніж limit → значить, це був останній пакет
                break;
            }

            $offset += $chunk;
        }

        $this->info("Done. Total imported/updated products: {$totalImported}");

        return self::SUCCESS;
    }

    protected function buildSearchIndex(?string $title, ?string $categoryPath, ?string $article): string
    {
        $pieces = array_filter([
            $title,
            $categoryPath,
            $article,
        ]);

        $string = Str::lower(implode(' ', $pieces));

        // мінімальна нормалізація
        $string = str_replace(['-', '_', '/', '\\'], ' ', $string);

        return trim($string);
    }
}
