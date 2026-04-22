<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantKnowledge;
use App\Services\Agent\Tools\KnowledgeLookupTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgeLookupToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_lookup_returns_compact_payload_and_bumps_usage(): void
    {
        $tenant = Tenant::create(['name' => 'T', 'slug' => 't', 'email' => 't@test', 'plan' => 'trial']);

        $row = TenantKnowledge::create([
            'tenant_id' => $tenant->id,
            'type' => TenantKnowledge::TYPE_FAQ,
            'question' => 'Чи є самовивіз у Львові?',
            'answer' => 'Так, самовивіз працює щодня.',
            'keywords' => ['самовивіз', 'львів'],
            'category' => 'logistics',
            'is_active' => true,
            'usage_count' => 0,
        ]);

        $tool = app(KnowledgeLookupTool::class);
        $result = $tool->lookup(['query' => 'самовивіз у Львові', 'limit' => 3], $tenant->id);

        $this->assertSame(1, $result['count']);
        $this->assertSame('faq', $result['results'][0]['type']);
        $this->assertStringContainsString('самовивіз', $result['results'][0]['answer']);
        // Compact payload — no score / articles keys leak to GPT.
        $this->assertArrayNotHasKey('score', $result['results'][0]);
        $this->assertArrayNotHasKey('articles', $result['results'][0]);

        $this->assertSame(1, (int) $row->fresh()->usage_count);
    }

    public function test_empty_query_returns_empty(): void
    {
        $tool = app(KnowledgeLookupTool::class);
        $this->assertSame(['results' => [], 'count' => 0], $tool->lookup(['query' => '   '], 1));
    }
}
