<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Horoshop\HoroshopClient;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Throwable;

class SyncHoroshopProducts extends Command
{
    protected $signature = 'sync:horoshop-products 
                            {--limit=500 : Скільки товарів тягнути за один запит (максимум 500)}';

    protected $description = 'Синхронізація товарів з Хорошопом у локальну БД';

    public function __construct(
        protected HoroshopClient $client,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Починаю синк товарів з Horoshop...');

        $limit = (int) $this->option('limit');
        if ($limit <= 0 || $limit > 500) {
            $limit = 500;
        }

        $offset     = 0;
        $totalSaved = 0;

        while (true) {
            try {
                $this->line("Запит catalog/export offset={$offset}, limit={$limit}...");

                $response = $this->client->request('catalog/export', [
                    'limit'          => $limit,
                    'offset'         => $offset,
                    'includedParams' => [
                        'title',
                        'price',
                        'price_old',
                        'article',
                        'slug',
                        'link',
                        'images',
                        'gallery_common',
                        'gallery_360',
                        'parent',
                    ],
                ]);

                // ВАЖЛИВО: Хорошоп повертає products, а не items
                $products = $response['products'] ?? [];

                if (empty($products)) {
                    $this->info('Більше товарів немає, синк завершено.');
                    break;
                }

                foreach ($products as $raw) {
                    $article = (string) ($raw['article'] ?? '');

                    if ($article === '') {
                        continue;
                    }

                    $titleRaw = $raw['title'] ?? '';
                    $titleUa  = '';
                    $titleRu  = '';

                    if (is_array($titleRaw)) {
                        $titleUa = (string) Arr::get($titleRaw, 'ua', '');
                        $titleRu = (string) Arr::get($titleRaw, 'ru', '');
                    } else {
                        $titleUa = (string) $titleRaw;
                    }

                    $categoryPath = (string) Arr::get($raw, 'parent.value', '');

                    $images = $raw['images']
                        ?? $raw['gallery_common']
                        ?? $raw['gallery_360']
                        ?? [];

                    $searchIndex = mb_strtolower(
                        trim($titleUa . ' ' . $titleRu . ' ' . $categoryPath),
                        'UTF-8'
                    );

                    Product::updateOrCreate(
                        ['article' => $article],
                        [
                            'title'         => $titleUa !== '' ? $titleUa : ($titleRu ?: $titleRaw),
                            'title_json'    => is_array($titleRaw) ? $titleRaw : null,
                            'price'         => $raw['price'] ?? null,
                            'price_old'     => $raw['price_old'] ?? null,
                            'category_path' => $categoryPath,
                            'slug'          => $raw['slug'] ?? null,
                            'link'          => $raw['link'] ?? null,
                            'images'        => $images,
                            'raw'           => $raw,
                            'search_index'  => $searchIndex,
                        ]
                    );

                    $totalSaved++;
                }

                $offset += $limit;

                // Якщо повернулось менше, ніж limit – це був останній пакет
                if (count($products) < $limit) {
                    $this->info('Останній пакет отримано, синк завершено.');
                    break;
                }
            } catch (Throwable $e) {
                $this->error('Помилка під час синку: ' . $e->getMessage());
                report($e);

                return self::FAILURE;
            }
        }

        $this->info("Синхронізація завершена. Оновлено/створено товарів: {$totalSaved}");

        return self::SUCCESS;
    }
}
