<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Services\Agent\FunctionCallingAgent;
use App\Services\Agent\Tools\MeiliProductSearchTool;
use App\Services\Agent\Tools\ProductDetailsTool;
use App\Services\Chat\PipelineTracer;
use App\Services\Horoshop\OrderSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * RAG audit trace tests.
 *
 * Validates that the PipelineTracer captures the three tiers needed to debug
 * bad responses (retrieval / context / prompt):
 *   1. user query           — pipeline.start
 *   2. retrieved context    — agent.tool_result
 *   3. system prompt        — agent.system_prompt
 */
class RagAuditTraceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create([
            'id' => 2,
            'name' => 'Test Shop',
            'slug' => 'test-shop',
            'domain' => 'https://test-shop.com',
            'owner_id' => 1,
            'plan' => 'starter',
        ]);

        Cache::flush();

        config([
            'services.openai.key' => 'sk-test-key-for-unit-tests',
            'services.openai.model' => 'gpt-4o',
            'services.openai.base_url' => 'https://api.openai.com/v1',
        ]);
    }

    private function makeAgent(?MeiliProductSearchTool $searchTool = null): FunctionCallingAgent
    {
        $searchTool ??= $this->createMock(MeiliProductSearchTool::class);
        $searchTool->method('getCurrentTenantId')->willReturn(2);
        $searchTool->method('search')->willReturn([
            [
                'id' => 101,
                'article' => 'tst-001',
                'title' => 'Тестовий шолом Ops-Core',
                'price' => 7500,
                'in_stock' => true,
                'category_path' => 'Спорядження / Шоломи',
            ],
            [
                'id' => 102,
                'article' => 'tst-002',
                'title' => 'Шолом балістичний MICH',
                'price' => 5200,
                'in_stock' => true,
                'category_path' => 'Спорядження / Шоломи',
            ],
        ]);
        $searchTool->method('detectAgeCategoryFromQuery')->willReturn(null);

        $detailsTool = $this->createMock(ProductDetailsTool::class);
        $detailsTool->method('getCards')->willReturn([]);

        $orderSearch = $this->createMock(OrderSearchService::class);

        return new FunctionCallingAgent($searchTool, $detailsTool, $orderSearch);
    }

    public function test_trace_captures_system_prompt_step(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => ['content' => 'Ось результати.', 'role' => 'assistant'],
                    'finish_reason' => 'stop',
                ]],
            ], 200),
        ]);

        $tracer = PipelineTracer::start('test-sess-prompt', 'розкажи про умови доставки замовлень');

        $agent = $this->makeAgent();
        $agent->handle('розкажи про умови доставки замовлень', ['session_id' => 'test-sess-prompt']);

        $trace = $tracer->finish();

        $promptStep = collect($trace['steps'])->firstWhere('step', 'agent.system_prompt');
        $this->assertNotNull($promptStep, 'Trace must include agent.system_prompt step');

        $data = $promptStep['data'];
        $this->assertArrayHasKey('source', $data);
        $this->assertContains($data['source'], ['custom_preset', 'modular', 'default_legacy']);
        $this->assertGreaterThan(100, $data['length_chars']);
        $this->assertNotEmpty($data['preview']);
        $this->assertArrayHasKey('hash', $data);
        $this->assertArrayHasKey('approx_tokens', $data);
    }

    public function test_trace_captures_tool_result_step_with_retrieved_context(): void
    {
        // Mock GPT to issue a search_products tool call, then wrap up with text.
        Http::fakeSequence('api.openai.com/*')
            ->push([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_abc',
                            'type' => 'function',
                            'function' => [
                                'name' => 'search_products',
                                'arguments' => json_encode(['query' => 'шолом']),
                            ],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
            ], 200)
            ->push([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'Ось шоломи.'],
                    'finish_reason' => 'stop',
                ]],
            ], 200);

        $tracer = PipelineTracer::start('test-sess-tool', 'покажи шоломи тактичні');

        $agent = $this->makeAgent();
        $agent->handle('покажи шоломи тактичні', ['session_id' => 'test-sess-tool']);

        $trace = $tracer->finish();

        $toolStep = collect($trace['steps'])->firstWhere('step', 'agent.tool_result');
        $this->assertNotNull($toolStep, 'Trace must include agent.tool_result step');

        $data = $toolStep['data'];
        $this->assertSame('search_products', $data['tool']);
        $this->assertSame('шолом', $data['args']['query']);
        $this->assertSame(2, $data['products_count']);
        $this->assertEqualsCanonicalizing(['tst-001', 'tst-002'], $data['articles']);
        $this->assertGreaterThan(0, $data['payload_bytes']);
        $this->assertEquals(['min' => 5200.0, 'max' => 7500.0], $data['price_range']);
        $this->assertCount(2, $data['first_titles']);
    }

    public function test_pipeline_start_captures_user_query(): void
    {
        $tracer = PipelineTracer::start('test-sess-q', 'що подарувати на рочок');

        $trace = $tracer->finish();
        $startStep = collect($trace['steps'])->firstWhere('step', 'pipeline.start');

        $this->assertNotNull($startStep);
        $this->assertSame('що подарувати на рочок', $startStep['data']['message']);
        $this->assertSame(4, $startStep['data']['word_count']);
    }
}
