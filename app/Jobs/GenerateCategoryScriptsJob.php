<?php

namespace App\Jobs;

use App\Models\CategoryScript;
use App\Models\Product;
use App\Services\Ai\AiRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class GenerateCategoryScriptsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected ?array $categoryKeys = null // якщо null – генеримо для всіх
    ) {
    }

    public function handle(AiRouter $aiRouter): void
    {
        // 1. Витягуємо унікальні category_path з товарів
        $paths = Product::query()
            ->whereNotNull('category_path')
            ->distinct()
            ->pluck('category_path')
            ->filter()
            ->values();

        foreach ($paths as $categoryPath) {
            // мапимо category_path → category_key (дуже грубо, ти потім допиляєш)
            $categoryKey = $this->mapCategoryPathToKey($categoryPath);

            if (! $categoryKey) {
                continue;
            }

            if ($this->categoryKeys && ! in_array($categoryKey, $this->categoryKeys, true)) {
                continue;
            }

            // 2. Викликаємо AiRouter/AI, щоб згенерувати питання для цієї категорії
            $scripts = $this->generateScriptsForCategory($aiRouter, $categoryKey, $categoryPath);

            // 3. Зберігаємо в БД
            foreach ($scripts as $level => $question) {
                if (! $question) {
                    continue;
                }

                CategoryScript::updateOrCreate(
                    [
                        'category_key' => $categoryKey,
                        'level'        => $level,
                    ],
                    [
                        'question_template' => $question,
                        'metadata'          => [
                            'source_category_path' => $categoryPath,
                        ],
                        'is_auto_generated' => true,
                    ]
                );
            }
        }
    }

    /**
     * Дуже груба мапа category_path → category_key.
     * Потім це можна винести в config і розширити.
     */
    protected function mapCategoryPathToKey(string $categoryPath): ?string
    {
        $norm = mb_strtolower($categoryPath);

        if (str_contains($norm, 'турнік') || str_contains($norm, 'турникі')) {
            return 'tourniquets';
        }

        if (str_contains($norm, 'аптечк') || str_contains($norm, 'ifak')) {
            return 'ifak_kits';
        }

        if (str_contains($norm, 'шолом') || str_contains($norm, 'каска')) {
            return 'helmets';
        }

        if (str_contains($norm, 'плитоноск')) {
            return 'plate_carriers';
        }

        if (str_contains($norm, 'плити') || str_contains($norm, 'бронеплити')) {
            return 'plates';
        }

        // Наприклад, все, що пов'язано з "зимою", "теплом" → cold_protection
        if (str_contains($norm, 'куртк') || str_contains($norm, 'зима') || str_contains($norm, 'утепл')) {
            return 'cold_protection';
        }

        return null;
    }

    /**
     * Виклик AI для генерації 1–2 питань по категорії.
     *
     * Ти можеш реалізувати це через окремий метод AiRouter, наприклад:
     * AiRouter::generateCategoryQuestions($categoryKey, $categoryPath): array
     */
    protected function generateScriptsForCategory(AiRouter $aiRouter, string $categoryKey, string $categoryPath): array
    {
        // Тут просто демо – ти підключиш реальний метод AiRouter
        // Зараз зробимо умовні шаблони, щоб воно працювало навіть без AI:

        switch ($categoryKey) {
            case 'tourniquets':
                return [
                    1 => "Для кого потрібен турнікет: піхота, водій, ТРО чи інше?",
                    2 => "Потрібен один турнікет чи кілька (2–3) в запас?",
                ];

            case 'ifak_kits':
                return [
                    1 => "Аптечка потрібна для піхоти, водія, медика чи цивільного використання?",
                    2 => "Хочеш готовий комплект IFAK чи зібрати аптечку з окремих позицій?",
                ];

            case 'helmets':
                return [
                    1 => "Шолом потрібен під конкретний стандарт захисту чи просто як базовий захист голови?",
                ];

            case 'cold_protection':
                return [
                    1 => "Щоб не замерзнути, що більше цікавить: куртка, термобілизна чи грілки?",
                    2 => "Плануєш використовувати при легкому морозі чи при сильному холоді (−10 і нижче)?",
                ];

            default:
                return [
                    1 => "Що саме тобі потрібно в категорії «{$categoryPath}»? Опиши коротко задачу або умови використання.",
                ];
        }
    }
}
