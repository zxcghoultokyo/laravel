<?php

namespace App\Services\Rozetka;

use App\Models\RozetkaCategory;
use Illuminate\Support\Facades\Log;

class RozetkaCategoryService
{
    public function __construct(
        protected RozetkaClient $client,
    ) {}

    /**
     * Sync all categories from Rozetka Seller API into local DB.
     * Returns the count of synced categories.
     */
    public function syncCategories(): int
    {
        if (! $this->client->isConfigured()) {
            throw new \RuntimeException('Rozetka client is not configured');
        }

        $page = 1;
        $perPage = 100;
        $totalSynced = 0;
        $allCategories = [];

        // Fetch all pages
        do {
            $response = $this->client->get('/items-create/categories', [
                'page' => $page,
                'per_page' => $perPage,
            ]);

            $categories = $response['content']['categories'] ?? [];
            $meta = $response['content']['_meta'] ?? [];
            $totalPages = $meta['pageCount'] ?? 1;

            foreach ($categories as $cat) {
                $allCategories[$cat['id']] = $cat;
            }

            $page++;
        } while ($page <= $totalPages);

        Log::info("Rozetka: fetched {$totalPages} pages, total categories: ".count($allCategories));

        // Build full paths using mpath
        $pathMap = $this->buildPathMap($allCategories);

        // Upsert into DB
        foreach ($allCategories as $cat) {
            RozetkaCategory::updateOrCreate(
                ['rozetka_id' => $cat['id']],
                [
                    'title_ua' => $cat['title_ua'] ?? $cat['title'] ?? '',
                    'title_ru' => $cat['title_ru'] ?? null,
                    'parent_rozetka_id' => $cat['parent_id'] ?? null,
                    'level' => $cat['level'] ?? 1,
                    'mpath' => $cat['mpath'] ?? null,
                    'full_path' => $pathMap[$cat['id']] ?? $cat['title_ua'] ?? '',
                    'is_vendor_required' => (bool) ($cat['is_vendor_required'] ?? false),
                ],
            );

            $totalSynced++;
        }

        Log::info("Rozetka: synced {$totalSynced} categories to DB");

        return $totalSynced;
    }

    /**
     * Build full human-readable paths from mpath + category titles.
     *
     * @param  array<int, array>  $categories  All categories indexed by id
     * @return array<int, string> Category id => "Parent > Child > Grandchild"
     */
    protected function buildPathMap(array $categories): array
    {
        $pathMap = [];

        foreach ($categories as $cat) {
            $mpath = $cat['mpath'] ?? '';
            // mpath looks like ".4627949.4628124.80001."
            $ids = array_filter(explode('.', $mpath));
            $parts = [];

            foreach ($ids as $id) {
                $id = (int) $id;
                if (isset($categories[$id])) {
                    $parts[] = $categories[$id]['title_ua'] ?? $categories[$id]['title'] ?? '';
                }
            }

            $pathMap[$cat['id']] = implode(' > ', $parts) ?: ($cat['title_ua'] ?? '');
        }

        return $pathMap;
    }

    /**
     * Search categories by name (for UI autocomplete).
     */
    public function searchCategories(string $query, int $limit = 20): array
    {
        return RozetkaCategory::where('title_ua', 'LIKE', "%{$query}%")
            ->orWhere('title_ru', 'LIKE', "%{$query}%")
            ->orWhere('full_path', 'LIKE', "%{$query}%")
            ->orderBy('level')
            ->orderBy('title_ua')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * Get full category tree (roots with children).
     */
    public function getCategoryTree(): array
    {
        $all = RozetkaCategory::orderBy('level')->orderBy('title_ua')->get();

        $byParent = [];
        foreach ($all as $cat) {
            $parentId = $cat->parent_rozetka_id ?? 0;
            $byParent[$parentId][] = $cat;
        }

        return $this->buildTree($byParent, 0);
    }

    protected function buildTree(array $byParent, int $parentId): array
    {
        $result = [];

        foreach ($byParent[$parentId] ?? [] as $cat) {
            $item = $cat->toArray();
            $children = $this->buildTree($byParent, $cat->rozetka_id);
            if ($children) {
                $item['children'] = $children;
            }
            $result[] = $item;
        }

        return $result;
    }

    /**
     * Get categories count in DB.
     */
    public function getCategoriesCount(): int
    {
        return RozetkaCategory::count();
    }
}
