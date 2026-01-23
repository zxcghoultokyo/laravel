<?php

namespace App\Services\Catalog;

use App\Models\Category;
use App\Models\CategoryAlias;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class CategoryIndexService
{
    /**
     * Rebuild categories for ALL tenants
     */
    public function rebuild(): void
    {
        // Get all tenant IDs that have products
        $tenantIds = DB::table('products')
            ->whereNotNull('tenant_id')
            ->distinct()
            ->pluck('tenant_id')
            ->toArray();
        
        Log::info('CategoryIndexService: rebuilding categories for tenants', [
            'tenant_ids' => $tenantIds,
        ]);
        
        foreach ($tenantIds as $tenantId) {
            $this->rebuildForTenant($tenantId);
        }
        
        // Also handle products without tenant_id (legacy)
        $this->rebuildForTenant(null);
    }
    
    /**
     * Rebuild categories for a specific tenant
     */
    public function rebuildForTenant(?int $tenantId): void
    {
        DB::transaction(function () use ($tenantId) {
            $query = DB::table('products')
                ->whereNotNull('category_path')
                ->where('category_path', '!=', '');
            
            if ($tenantId) {
                $query->where('tenant_id', $tenantId);
            } else {
                $query->whereNull('tenant_id');
            }
            
            $paths = $query
                ->select('category_path', DB::raw('COUNT(*) as cnt'))
                ->groupBy('category_path')
                ->orderByDesc('cnt')
                ->get();

            Log::info('CategoryIndexService: found categories for tenant', [
                'tenant_id' => $tenantId,
                'count' => $paths->count(),
            ]);

            foreach ($paths as $row) {
                $path = (string) $row->category_path;
                $cnt  = (int) $row->cnt;

                // Make slug unique per tenant
                $slugBase = Str::slug($path, '_');
                if ($slugBase === '') {
                    $slugBase = 'category_' . crc32($path);
                }
                $slug = $tenantId ? "t{$tenantId}_{$slugBase}" : $slugBase;

                // Use withoutGlobalScope to avoid tenant filtering during upsert
                $cat = Category::withoutGlobalScope(TenantScope::class)->updateOrCreate(
                    ['tenant_id' => $tenantId, 'path' => $path],
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