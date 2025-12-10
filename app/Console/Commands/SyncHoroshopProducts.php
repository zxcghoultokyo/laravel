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

                        // НОВІ ПОЛЯ ДЛЯ НАЯВНОСТІ / ПРІОРИТЕТУ
                        'display_in_showcase',
                        'presence',
                        'residues',
                        'quantity',
                        'popularity',
                        'we_recommended',
                        'color',
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

                    // ===== НОВІ ПОЛЯ З HOROSHOP =====
                    $displayInShowcase = (bool) Arr::get($raw, 'display_in_showcase', 1);

                    $presence = $this->extractPresence($raw);

                    $quantity = $this->extractQuantity($raw);

                    $popularity = (int) Arr::get($raw, 'popularity', 0);

                    $weRecommended = (bool) Arr::get($raw, 'we_recommended', 0);

                    $color = $this->extractColor($raw);

                    $inStock = $this->computeInStock($presence, $quantity);

                    Product::updateOrCreate(
                        ['article' => $article],
                        [
                            'title'               => $titleUa !== '' ? $titleUa : ($titleRu ?: $titleRaw),
                            'title_json'          => is_array($titleRaw) ? $titleRaw : null,
                            'price'               => $raw['price'] ?? null,
                            'price_old'           => $raw['price_old'] ?? null,
                            'category_path'       => $categoryPath,
                            'slug'                => $raw['slug'] ?? null,
                            'link'                => $raw['link'] ?? null,
                            'images'              => $images,
                            'raw'                 => $raw,
                            'search_index'        => $searchIndex,

                            // НОВЕ
                            'display_in_showcase' => $displayInShowcase,
                            'presence'            => $presence,
                            'quantity'            => $quantity,
                            'popularity'          => $popularity,
                            'we_recommended'      => $weRecommended,
                            'color'               => $color,
                            'in_stock'            => $inStock,
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

    /**
     * Дістаємо presence як людинозрозумілий рядок (українська/російська).
     */
    protected function extractPresence(array $raw): ?string
    {
        $presence = $raw['presence'] ?? null;

        if ($presence === null) {
            return null;
        }

        // формат з доки: ['id' => ..., 'value' => ['ua' => '...', 'ru' => '...']]
        $value = $presence['value'] ?? null;

        if (is_array($value)) {
            return (string) ($value['ua'] ?? $value['ru'] ?? reset($value) ?? '');
        }

        if (is_string($value)) {
            return $value;
        }

        // fallback: якщо presence одразу рядок
        if (is_string($presence)) {
            return $presence;
        }

        return null;
    }

    /**
     * Рахуємо загальну кількість (quantity) по складах або з поля quantity, якщо воно є.
     */
    protected function extractQuantity(array $raw): int
    {
        // якщо ввімкнено облік залишків — через residues
        $residues = $raw['residues'] ?? null;

        if (is_array($residues)) {
            $total = 0;

            foreach ($residues as $r) {
                $total += (int) ($r['quantity'] ?? 0);
            }

            return $total;
        }

        // якщо Horoshop віддає quantity напряму
        if (isset($raw['quantity'])) {
            return (int) $raw['quantity'];
        }

        return 0;
    }

    /**
     * Дістаємо колір як текст (ua/ru/або перше значення масиву).
     */
    protected function extractColor(array $raw): ?string
    {
        $color = $raw['color'] ?? null;

        if ($color === null) {
            return null;
        }

        $value = $color['value'] ?? null;

        if (is_array($value)) {
            return (string) ($value['ua'] ?? $value['ru'] ?? reset($value) ?? '');
        }

        if (is_string($value)) {
            return $value;
        }

        // fallback: якщо color одразу рядок
        if (is_string($color)) {
            return $color;
        }

        return null;
    }

    /**
     * Агрегуємо in_stock по presence + quantity.
     *
     * Логіка:
     *  - якщо quantity > 0 → in_stock = true;
     *  - якщо quantity = 0, але presence містить "в наявності" → true;
     *  - якщо явно "немає в наявності" → false;
     *  - інакше — false за замовчуванням.
     */
    protected function computeInStock(?string $presence, int $quantity): bool
    {
        $presenceNorm = mb_strtolower($presence ?? '');

        // якщо є реальні залишки на складах — це найнадійніший сигнал
        if ($quantity > 0) {
            return true;
        }

        // явна відсутність
        if ($presenceNorm !== '') {
            if (
                str_contains($presenceNorm, 'немає в наявності') ||
                str_contains($presenceNorm, 'нет в наличии')
            ) {
                return false;
            }
        }

        // якщо quantity = 0, але presence каже "в наявності" — вважаємо, що товар є
        if (
            str_contains($presenceNorm, 'в наявності') ||
            str_contains($presenceNorm, 'у наявності') ||
            str_contains($presenceNorm, 'в наличии')
        ) {
            return true;
        }

        // за замовчуванням — немає в наявності
        return false;
    }
}
