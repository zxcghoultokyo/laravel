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
        $limit = (int) $this->option('limit') ?: 500;

        if ($limit > 500) {
            $limit = 500;
        }

        if ($limit < 1) {
            $limit = 100;
        }

        $offset     = 0;
        $totalSaved = 0;

        $this->info("Старт синхронізації товарів із Horoshop (limit={$limit})");

        while (true) {
            try {
                $this->info("→ Отримуємо товари: offset={$offset}, limit={$limit}");

                // Викликаємо Хорошоп через наш клієнт (з login/password + token)
                $response = $this->client->request('catalog/export', [
                    'limit'  => $limit,
                    'offset' => $offset,
                ]);

                /**
                 * Horoshop зазвичай повертає просто масив товарів.
                 * Якщо раптом відповідь буде формату ['items' => [...]],
                 * ми це теж врахуємо.
                 */
                $items = is_array($response) ? $response : [];
                $items = Arr::isAssoc($items)
                    ? Arr::get($items, 'items', [])
                    : $items;

                if (empty($items)) {
                    $this->info('Отримано порожній список товарів, зупиняємось.');
                    break;
                }

                $batchSaved = 0;

                foreach ($items as $row) {
                    // Підлаштовуємося під типовий формат catalog/export
                    $article = $row['article'] ?? $row['sku'] ?? null;
                    if (! $article) {
                        continue;
                    }

                    $titleUa = $row['title_uk'] ?? $row['title'] ?? null;

                    $titleJson = [
                        'uk' => $row['title_uk'] ?? null,
                        'ru' => $row['title_ru'] ?? null,
                        'en' => $row['title_en'] ?? null,
                    ];

                    $categoryPath = $row['category_path'] ?? null;
                    $slug         = $row['slug'] ?? null;
                    $link         = $row['url'] ?? null;

                    $images = $row['images'] ?? [];
                    if (! is_array($images)) {
                        $images = [$images];
                    }

                    $searchIndex = $this->buildSearchIndex($titleUa, $categoryPath, $article);

                    Product::updateOrCreate(
                        ['article' => $article],
                        [
                            'title'         => $titleUa,
                            'title_json'    => $titleJson,
                            'price'         => $row['price'] ?? null,
                            'price_old'     => $row['old_price'] ?? null,
                            'category_path' => $categoryPath,
                            'slug'          => $slug,
                            'link'          => $link,
                            'images'        => $images,
                            'raw'           => $row,
                            'search_index'  => $searchIndex,
                        ]
                    );

                    $batchSaved++;
                    $totalSaved++;
                }

                $this->info("Збережено/оновлено в цьому пакеті: {$batchSaved}");

                // Якщо повернуло менше товарів, ніж limit — це остання сторінка
                if ($batchSaved < $limit) {
                    $this->info('Отримано менше товарів, ніж limit — вважаємо це останнім пакетом.');
                    break;
                }

                $offset += $limit;
            } catch (Throwable $e) {
                $this->error('Помилка під час синку: ' . $e->getMessage());
                report($e);

                return self::FAILURE;
            }
        }

        $this->info("Синхронізація завершена. Оновлено/створено товарів: {$totalSaved}");

        return self::SUCCESS;
    }

    protected function buildSearchIndex(?string $title, ?string $categoryPath, ?string $article): string
    {
        $parts = array_filter([$title, $categoryPath, $article]);
        $str   = mb_strtolower(implode(' ', $parts), 'UTF-8');

        // Мінімальна нормалізація
        $str = str_replace(['-', '_', '/', '\\'], ' ', $str);
        $str = preg_replace('/\s+/', ' ', $str);

        return trim($str);
    }
}
