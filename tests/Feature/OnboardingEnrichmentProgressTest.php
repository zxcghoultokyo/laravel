<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductAiIndex;
use App\Models\Tenant;
use App\Models\TenantOnboardingProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression: enrichmentProgress endpoint must count AI-enriched products
 * by joining via products.tenant_id (product_ai_index has no tenant_id column).
 */
class OnboardingEnrichmentProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_enrichment_progress_counts_enriched_products_for_tenant(): void
    {
        $tenant = Tenant::create([
            'name' => 'Shop A',
            'slug' => 'shop-a',
            'email' => 'a@a.com',
            'platform' => 'horoshop',
        ]);
        $otherTenant = Tenant::create([
            'name' => 'Shop B',
            'slug' => 'shop-b',
            'email' => 'b@b.com',
            'platform' => 'horoshop',
        ]);

        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        // Tenant A: 3 in-stock products, 2 enriched; 1 out-of-stock enriched (ignored by totalProducts)
        $p1 = Product::create(['tenant_id' => $tenant->id, 'article' => 'A1', 'title' => 'P1', 'in_stock' => true, 'search_index' => 'idx1']);
        $p2 = Product::create(['tenant_id' => $tenant->id, 'article' => 'A2', 'title' => 'P2', 'in_stock' => true, 'search_index' => 'idx2']);
        $p3 = Product::create(['tenant_id' => $tenant->id, 'article' => 'A3', 'title' => 'P3', 'in_stock' => true, 'search_index' => '']);

        ProductAiIndex::create(['product_id' => $p1->id, 'keywords' => ['foo']]);
        ProductAiIndex::create(['product_id' => $p2->id, 'keywords' => ['bar']]);
        // p3 not enriched

        // Other tenant enriched product must not leak into count
        $pOther = Product::create(['tenant_id' => $otherTenant->id, 'article' => 'B1', 'title' => 'PB', 'in_stock' => true, 'search_index' => 'idxB']);
        ProductAiIndex::create(['product_id' => $pOther->id, 'keywords' => ['leak']]);

        $response = $this->actingAs($user)->getJson(route('onboarding.enrichment.progress'));

        $response->assertOk()
            ->assertJsonPath('total_products', 3)
            ->assertJsonPath('ai_enrichment.completed', 2)
            ->assertJsonPath('meili_indexing.completed', 2);
    }

    public function test_endpoint_prefers_onboarding_progress_when_live_counts_drift(): void
    {
        // Simulates post-resync drift: product_ai_index rows orphaned (FK mismatch),
        // but TenantOnboardingProgress recorded completion with 830/830 enriched.
        $tenant = Tenant::create([
            'name' => 'Shop C',
            'slug' => 'shop-c',
            'email' => 'c@c.com',
            'platform' => 'horoshop',
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        // 830 fresh in-stock products (no ai_index rows — simulating drift)
        for ($i = 1; $i <= 5; $i++) {
            Product::create([
                'tenant_id' => $tenant->id,
                'article' => "C{$i}",
                'title' => "P{$i}",
                'in_stock' => true,
                'search_index' => "idx{$i}",
            ]);
        }

        $progress = TenantOnboardingProgress::forTenant($tenant->id);
        $progress->update([
            'status' => 'completed',
            'overall_percent' => 100,
            'steps' => array_merge(TenantOnboardingProgress::initializeSteps(), [
                'ai_enrichment' => [
                    'status' => 'completed',
                    'percent' => 100,
                    'detail' => 'AI аналіз завершено: 830 товарів оброблено',
                    'started_at' => now()->subHour()->toIso8601String(),
                    'completed_at' => now()->toIso8601String(),
                    'stats' => ['total' => 830, 'enriched' => 830, 'processed' => 830],
                ],
                'meili_indexing' => [
                    'status' => 'completed',
                    'percent' => 100,
                    'detail' => 'Проіндексовано 830 товарів',
                    'started_at' => now()->subHour()->toIso8601String(),
                    'completed_at' => now()->toIso8601String(),
                    'stats' => ['processed' => 830],
                ],
            ]),
            'completed_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson(route('onboarding.enrichment.progress'));

        $response->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('ai_enrichment.completed', 830)
            ->assertJsonPath('ai_enrichment.percent', 100)
            ->assertJsonPath('meili_indexing.completed', 830)
            ->assertJsonPath('meili_indexing.percent', 100)
            ->assertJsonPath('overall_percent', 100);
    }
}
