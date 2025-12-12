<?php

namespace App\Services\Catalog;

use App\Models\Category;
use App\Models\CategoryAlias;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CategoryIndexService
{
    public function rebuild(): void
    {
        DB::transaction(function () {
            $paths = DB::table('products')
                ->whereNotNull('category_path')
                ->where('category_path', '!=', '')
                ->select('category_path', DB::raw('COUNT(*) as cnt'))
                ->groupBy('category_path')
                ->orderByDesc('cnt')
                ->get();

            foreach ($paths as $row) {
                $path = (string) $row->category_path;
                $cnt  = (int) $row->cnt;

                $slug = Str::slug($path, '_');
                if ($slug === '') {
                    $slug = 'category_' . crc32($path);
                }

                $cat = Category::query()->updateOrCreate(
                    ['path' => $path],
                    [
                        'slug'           => $slug,
                        'path_norm'      => $this->norm($path),
                        'products_count' => $cnt,
                        'is_active'      => true,
                    ]
                );

                $this->upsertAliases($cat->id, $path);
            }
        });
    }

    protected function upsertAliases(int $categoryId, string $path): void
    {
        $segments = preg_split('#\\s*/\\s*#u', $path) ?: [];
        $segments = array_values(array_filter(array_map('trim', $segments)));

        // 1) повний шлях як alias
        $this->alias($categoryId, $path, 50, 'full_path');

        foreach ($segments as $seg) {
            $this->alias($categoryId, $seg, 30, 'segment');

            // 2) токени сегмента (наприклад "Тактична медицина" -> ["тактична","медицина"])
            $tokens = preg_split('/\\s+/u', $seg) ?: [];
            foreach ($tokens as $t) {
                $t = trim($t);
                if (mb_strlen($t) < 3) continue;
                $this->alias($categoryId, $t, 15, 'token');
            }
        }
    }

    protected function alias(int $categoryId, string $phrase, int $weight, string $source): void
    {
        $norm = $this->norm($phrase);
        if ($norm === '' || mb_strlen($norm) < 3) {
            return;
        }

        CategoryAlias::query()->updateOrCreate(
            ['category_id' => $categoryId, 'phrase_norm' => $norm],
            [
                'phrase'    => $phrase,
                'weight'    => $weight,
                'source'    => $source,
                'is_active' => true,
            ]
        );
    }

    protected function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/[^\\p{L}\\p{N}\\s\\-]+/u', ' ', $s) ?: '';
        $s = preg_replace('/\\s+/u', ' ', $s) ?: '';
        return trim($s);
    }
}
