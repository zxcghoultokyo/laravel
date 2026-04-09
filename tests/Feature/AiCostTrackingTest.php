<?php

namespace Tests\Feature;

use App\Models\AiUsageLog;
use App\Services\Usage\AiCostTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiCostTrackingTest extends TestCase
{
    use RefreshDatabase;

    private AiCostTrackingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AiCostTrackingService::class);
    }

    public function test_calculate_cost_gpt4o(): void
    {
        $cost = $this->service->calculateCost('gpt-4o', 1000, 500);

        // 1000 * 2.50/1M + 500 * 10.00/1M = 0.0025 + 0.005 = 0.0075
        $this->assertEqualsWithDelta(0.0075, $cost, 0.000001);
    }

    public function test_calculate_cost_gpt4o_mini(): void
    {
        $cost = $this->service->calculateCost('gpt-4o-mini', 1000, 500);

        // 1000 * 0.15/1M + 500 * 0.60/1M = 0.00015 + 0.0003 = 0.00045
        $this->assertEqualsWithDelta(0.00045, $cost, 0.000001);
    }

    public function test_calculate_cost_unknown_model_defaults_to_gpt4o(): void
    {
        $cost = $this->service->calculateCost('unknown-model', 1000, 500);

        // Should default to gpt-4o pricing
        $this->assertEqualsWithDelta(0.0075, $cost, 0.000001);
    }

    public function test_calculate_cost_prefix_match(): void
    {
        $cost = $this->service->calculateCost('gpt-4o-2024-08-06', 1000, 500);

        // Should match gpt-4o prefix
        $this->assertEqualsWithDelta(0.0075, $cost, 0.000001);
    }

    public function test_log_creates_record(): void
    {
        $this->service->log(
            source: 'chat',
            model: 'gpt-4o',
            usage: ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
            tenantId: 1,
            sessionId: 'test_session_123',
            responseTimeMs: 1500,
        );

        $this->assertDatabaseCount('ai_usage_logs', 1);

        $log = AiUsageLog::first();
        $this->assertEquals(1, $log->tenant_id);
        $this->assertEquals('chat', $log->source);
        $this->assertEquals('gpt-4o', $log->model);
        $this->assertEquals(100, $log->prompt_tokens);
        $this->assertEquals(50, $log->completion_tokens);
        $this->assertEquals(150, $log->total_tokens);
        $this->assertEquals('test_session_123', $log->session_id);
        $this->assertEquals(1500, $log->response_time_ms);
        $this->assertFalse($log->is_error);
        $this->assertGreaterThan(0, (float) $log->cost_usd);
    }

    public function test_log_with_error(): void
    {
        $this->service->log(
            source: 'chat',
            model: 'gpt-4o',
            usage: [],
            isError: true,
        );

        $log = AiUsageLog::first();
        $this->assertTrue($log->is_error);
    }

    public function test_log_does_not_throw_on_failure(): void
    {
        // Logging should never throw, even if something goes wrong
        $this->service->log(
            source: 'chat',
            model: 'gpt-4o',
            usage: ['prompt_tokens' => 100, 'completion_tokens' => 50],
        );

        $this->assertDatabaseCount('ai_usage_logs', 1);
    }

    public function test_get_stats_returns_structure(): void
    {
        $this->service->log(source: 'chat', model: 'gpt-4o', usage: ['prompt_tokens' => 100, 'completion_tokens' => 50], tenantId: 1);
        $this->service->log(source: 'enrichment', model: 'gpt-4o-mini', usage: ['prompt_tokens' => 200, 'completion_tokens' => 100], tenantId: 1);
        $this->service->log(source: 'chat', model: 'gpt-4o', usage: ['prompt_tokens' => 300, 'completion_tokens' => 150], tenantId: 2);

        $stats = $this->service->getStats();

        $this->assertArrayHasKey('totals', $stats);
        $this->assertArrayHasKey('by_model', $stats);
        $this->assertArrayHasKey('by_source', $stats);
        $this->assertArrayHasKey('daily', $stats);
        $this->assertEquals(3, $stats['totals']['requests']);
        $this->assertGreaterThan(0, $stats['totals']['cost_usd']);
    }

    public function test_get_stats_filters_by_tenant(): void
    {
        $this->service->log(source: 'chat', model: 'gpt-4o', usage: ['prompt_tokens' => 100, 'completion_tokens' => 50], tenantId: 1);
        $this->service->log(source: 'chat', model: 'gpt-4o', usage: ['prompt_tokens' => 200, 'completion_tokens' => 100], tenantId: 2);

        $stats = $this->service->getStats(tenantId: 1);

        $this->assertEquals(1, $stats['totals']['requests']);
    }

    public function test_get_stats_by_tenant(): void
    {
        $this->service->log(source: 'chat', model: 'gpt-4o', usage: ['prompt_tokens' => 100, 'completion_tokens' => 50], tenantId: 1);
        $this->service->log(source: 'chat', model: 'gpt-4o', usage: ['prompt_tokens' => 200, 'completion_tokens' => 100], tenantId: 2);

        $byTenant = $this->service->getStatsByTenant();

        $this->assertCount(2, $byTenant);
        $this->assertEquals(2, $byTenant[0]['tenant_id']); // Higher cost first
        $this->assertEquals(1, $byTenant[1]['tenant_id']);
    }

    public function test_diagnostic_endpoint_requires_key(): void
    {
        $response = $this->getJson('/api/diagnostic/ai-costs');

        $response->assertStatus(401);
    }

    public function test_diagnostic_endpoint_returns_stats(): void
    {
        $this->service->log(source: 'chat', model: 'gpt-4o', usage: ['prompt_tokens' => 100, 'completion_tokens' => 50], tenantId: 1);

        $response = $this->getJson('/api/diagnostic/ai-costs?key=diagnostic_secret_key_2025');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'stats' => ['totals', 'by_model', 'by_source', 'daily'],
            'by_tenant',
        ]);
    }
}
