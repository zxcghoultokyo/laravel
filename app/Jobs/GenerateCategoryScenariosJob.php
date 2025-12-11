<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\ProductTag;
use App\Models\Scenario;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class GenerateCategoryScenariosJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        // 1. Витягуємо унікальні category_path
        $categories = Product::query()
            ->whereNotNull('category_path')
            ->distinct()
            ->pluck('category_path');

        foreach ($categories as $categoryPath) {
            $this->handleCategory($categoryPath);
        }
    }

    protected function handleCategory(string $categoryPath): void
    {
        // Код сценарію
        $code = 'category_' . Str::slug($categoryPath, '_');

        // 2. Створюємо/оновлюємо Scenario
        /** @var Scenario $scenario */
        $scenario = Scenario::query()->firstOrNew(['code' => $code]);

        $scenario->fill([
            'name'          => $categoryPath,
            'description'   => 'Автогенерований сценарій для категорії: ' . $categoryPath,
            'handler_class' => \App\Services\Chat\Handlers\CategoryScenarioHandler::class, // приклад
            'is_active'     => true,
            'config'        => [
                'category_path' => $categoryPath,
                // сюди можна докинути сконфігуровані фрази, теги, підказки для бота
            ],
        ]);

        $scenario->save();

        // 3. Приклад автогенерації тега на категорію
        $tagName = $categoryPath;

        $tag = ProductTag::query()->firstOrCreate(
            [
                'slug'  => Str::slug($tagName),
                'type'  => 'category',
                'domain'=> 'horoshop',
            ],
            [
                'name'             => $tagName,
                'is_auto_generated'=> true,
            ]
        );

        // 4. Прив’язуємо тег до всіх товарів цієї категорії
        $products = Product::query()
            ->where('category_path', $categoryPath)
            ->get();

        foreach ($products as $product) {
            $product->tags()->syncWithoutDetaching([$tag->id]);
        }
    }
}
