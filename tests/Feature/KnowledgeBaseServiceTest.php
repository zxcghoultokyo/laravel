<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantKnowledge;
use App\Services\Knowledge\KnowledgeBaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgeBaseServiceTest extends TestCase
{
    use RefreshDatabase;

    private KnowledgeBaseService $service;

    private Tenant $tenantA;

    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(KnowledgeBaseService::class);
        $this->tenantA = Tenant::create(['name' => 'A', 'slug' => 'a', 'email' => 'a@test', 'plan' => 'trial']);
        $this->tenantB = Tenant::create(['name' => 'B', 'slug' => 'b', 'email' => 'b@test', 'plan' => 'trial']);
    }

    public function test_search_returns_tenant_scoped_results(): void
    {
        TenantKnowledge::create([
            'tenant_id' => $this->tenantA->id,
            'type' => TenantKnowledge::TYPE_FAQ,
            'question' => 'Як оформити доставку Новою поштою?',
            'answer' => 'Оформлюємо доставку Новою поштою протягом дня.',
            'keywords' => ['доставка', 'нова', 'пошта'],
            'is_active' => true,
            'priority' => 50,
        ]);

        TenantKnowledge::create([
            'tenant_id' => $this->tenantB->id,
            'type' => TenantKnowledge::TYPE_FAQ,
            'question' => 'Як оформити доставку?',
            'answer' => 'Інша відповідь іншого тенанта.',
            'keywords' => ['доставка'],
            'is_active' => true,
            'priority' => 50,
        ]);

        $results = $this->service->search('як зробити доставку новою поштою', null, 5, $this->tenantA->id);

        $this->assertNotEmpty($results);
        $this->assertSame(TenantKnowledge::TYPE_FAQ, $results[0]['type']);
        $this->assertStringContainsString('Новою поштою', $results[0]['answer']);
        foreach ($results as $row) {
            $this->assertSame($this->tenantA->id, TenantKnowledge::withoutGlobalScopes()->find($row['id'])->tenant_id);
        }
    }

    public function test_search_filters_by_type(): void
    {
        TenantKnowledge::create([
            'tenant_id' => $this->tenantA->id,
            'type' => TenantKnowledge::TYPE_FAQ,
            'question' => 'Чи є оплата карткою?',
            'answer' => 'Так, приймаємо картки.',
            'keywords' => ['оплата', 'картка'],
            'is_active' => true,
        ]);
        TenantKnowledge::create([
            'tenant_id' => $this->tenantA->id,
            'type' => TenantKnowledge::TYPE_SCRIPT,
            'question' => 'Тон спілкування',
            'answer' => 'Звертайся на Ви, без канцеляризмів.',
            'keywords' => ['оплата'],
            'is_active' => true,
        ]);

        $faqOnly = $this->service->search('оплата', [TenantKnowledge::TYPE_FAQ], 5, $this->tenantA->id);
        $this->assertCount(1, $faqOnly);
        $this->assertSame(TenantKnowledge::TYPE_FAQ, $faqOnly[0]['type']);
    }

    public function test_search_returns_empty_for_unknown_tenant(): void
    {
        TenantKnowledge::create([
            'tenant_id' => $this->tenantA->id,
            'type' => TenantKnowledge::TYPE_FAQ,
            'question' => 'Чи безпечна фарба?',
            'answer' => 'Так, сертифіковано.',
            'keywords' => ['фарба'],
            'is_active' => true,
        ]);

        $this->assertSame([], $this->service->search('фарба', null, 5, 99999));
    }

    public function test_get_hints_for_articles_returns_map(): void
    {
        TenantKnowledge::create([
            'tenant_id' => $this->tenantA->id,
            'type' => TenantKnowledge::TYPE_PRODUCT_HINT,
            'question' => 'Диски Монтессорі',
            'answer' => 'Сенсорні диски для малюків.',
            'articles' => ['disk-001', 'disk-002'],
            'is_active' => true,
        ]);

        $map = $this->service->getHintsForArticles(['disk-001', 'unknown'], $this->tenantA->id);
        $this->assertArrayHasKey('disk-001', $map);
        $this->assertArrayNotHasKey('unknown', $map);
        $this->assertStringContainsString('Сенсорні диски', $map['disk-001']);
    }

    public function test_increment_usage_bumps_counter(): void
    {
        $row = TenantKnowledge::create([
            'tenant_id' => $this->tenantA->id,
            'type' => TenantKnowledge::TYPE_FAQ,
            'question' => 'q',
            'answer' => 'a',
            'is_active' => true,
            'usage_count' => 0,
        ]);

        $this->service->incrementUsage($row->id);
        $this->service->incrementUsage($row->id);

        $this->assertSame(2, (int) $row->fresh()->usage_count);
    }
}
