<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Services\Agent\StreamingFunctionCallingAgent;
use App\Services\Agent\Tools\MeiliProductSearchTool;
use App\Services\Agent\Tools\ProductDetailsTool;
use App\Services\Horoshop\OrderSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests for StreamingFunctionCallingAgent — SSE streaming chat agent.
 * Mirrors FunctionCallingAgentTest patterns for the streaming variant.
 */
class StreamingFunctionCallingAgentTest extends TestCase
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

    private function makeAgent(
        ?MeiliProductSearchTool $searchTool = null,
        ?ProductDetailsTool $detailsTool = null,
    ): StreamingFunctionCallingAgent {
        $searchTool ??= $this->createMock(MeiliProductSearchTool::class);
        $searchTool->method('getCurrentTenantId')->willReturn(2);
        $searchTool->method('search')->willReturn([]);

        $detailsTool ??= $this->createMock(ProductDetailsTool::class);
        $detailsTool->method('getCards')->willReturn([]);

        $orderSearch = $this->createMock(OrderSearchService::class);

        return new StreamingFunctionCallingAgent($searchTool, $detailsTool, $orderSearch);
    }

    /**
     * Collect all events from the streaming generator.
     */
    private function collectEvents(StreamingFunctionCallingAgent $agent, string $message, string $sessionId = 'test_sess'): array
    {
        $events = [];
        foreach ($agent->stream($message, $sessionId) as $event) {
            $events[] = $event;
        }

        return $events;
    }

    // ───────────────────────────────────────────────
    // Greeting interception (no GPT call)
    // ───────────────────────────────────────────────

    public function test_stream_intercepted_greeting(): void
    {
        Http::fake();

        $agent = $this->makeAgent();
        $events = $this->collectEvents($agent, 'привіт', 'test_greet');

        $types = array_column($events, 'type');
        $this->assertContains('done', $types, 'Stream should end with done');
        $this->assertContains('text', $types, 'Greeting should produce text event');

        Http::assertNothingSent();
    }

    // ───────────────────────────────────────────────
    // GPT text response (no tool calls)
    // ───────────────────────────────────────────────

    public function test_stream_text_response_from_gpt(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Це тестова відповідь від GPT.',
                            'role' => 'assistant',
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
            ], 200),
        ]);

        $agent = $this->makeAgent();
        $events = $this->collectEvents($agent, 'розкажи про умови доставки замовлень', 'test_text');

        $types = array_column($events, 'type');
        $this->assertContains('done', $types);

        // Agent may stream via 'chunk' (char-by-char) or 'text' (single event)
        $textEvents = array_filter($events, fn ($e) => in_array($e['type'], ['chunk', 'text']));
        $this->assertNotEmpty($textEvents, 'Should stream text events');
    }

    // ───────────────────────────────────────────────
    // GPT text response containing article references
    // (This is the exact code path that had $this->tenantId bug)
    // ───────────────────────────────────────────────

    public function test_stream_text_response_with_article_references_no_crash(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Рекомендую шолом (арт. ABC-123) — чудова якість.',
                            'role' => 'assistant',
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
            ], 200),
        ]);

        $agent = $this->makeAgent();

        // This must NOT throw ErrorException: Undefined property $tenantId
        $events = $this->collectEvents($agent, 'порадь хороший шолом для виїзних місій', 'test_article_ref');

        $types = array_column($events, 'type');
        $this->assertContains('done', $types, 'Stream should complete without crash');
    }

    // ───────────────────────────────────────────────
    // Fallback on empty API key
    // ───────────────────────────────────────────────

    public function test_stream_falls_back_on_empty_api_key(): void
    {
        config(['services.openai.key' => '']);

        $agent = $this->makeAgent();
        $events = $this->collectEvents($agent, 'порекомендуй тактичні рюкзаки', 'test_no_key');

        $types = array_column($events, 'type');
        $this->assertContains('done', $types);

        $chunks = array_filter($events, fn ($e) => $e['type'] === 'chunk');
        $this->assertNotEmpty($chunks, 'Should produce fallback text');
    }

    // ───────────────────────────────────────────────
    // Fallback on GPT error (exhausted retries)
    // ───────────────────────────────────────────────

    public function test_stream_falls_back_on_server_error(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'error' => [
                    'message' => 'The server had an error',
                    'type' => 'server_error',
                    'code' => 'server_error',
                ],
            ], 500),
        ]);

        $agent = $this->makeAgent();
        $events = $this->collectEvents($agent, 'покажи штани для зимових місій', 'test_500');

        $types = array_column($events, 'type');
        $this->assertContains('done', $types, 'Stream should end gracefully');

        $chunks = array_filter($events, fn ($e) => $e['type'] === 'chunk');
        $this->assertNotEmpty($chunks, 'Should produce fallback text after error');

        // 1 initial + 3 retries = 4 calls
        Http::assertSentCount(4);
    }

    // ───────────────────────────────────────────────
    // Quota error
    // ───────────────────────────────────────────────

    public function test_stream_falls_back_on_quota_error(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'error' => [
                    'message' => 'You exceeded your current quota',
                    'type' => 'insufficient_quota',
                    'code' => 'insufficient_quota',
                ],
            ], 429),
        ]);

        $agent = $this->makeAgent();
        $events = $this->collectEvents($agent, 'покажи тактичні наколінники', 'test_quota');

        $types = array_column($events, 'type');
        $this->assertContains('done', $types);

        // insufficient_quota is not retryable
        Http::assertSentCount(1);
    }

    // ───────────────────────────────────────────────
    // Short query handler
    // ───────────────────────────────────────────────

    public function test_stream_single_word_uses_short_query_handler(): void
    {
        $searchTool = $this->createMock(MeiliProductSearchTool::class);
        $searchTool->method('getCurrentTenantId')->willReturn(2);
        $searchTool->method('search')->willReturn([
            ['id' => 1, 'title' => 'Тактичний шолом', 'article' => 'H-1', 'price' => 5000],
        ]);

        $detailsTool = $this->createMock(ProductDetailsTool::class);
        $detailsTool->method('getCards')->willReturn([
            ['id' => 1, 'title' => 'Тактичний шолом', 'article' => 'H-1', 'price' => 5000],
        ]);

        $agent = $this->makeAgent($searchTool, $detailsTool);
        $events = $this->collectEvents($agent, 'шоломи', 'test_short');

        $types = array_column($events, 'type');
        $this->assertContains('products', $types, 'Short query should return products');
        $this->assertContains('done', $types);

        Http::assertNothingSent();
    }

    // ───────────────────────────────────────────────
    // URL stripping
    // ───────────────────────────────────────────────

    public function test_stream_strips_urls_from_gpt_response(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Ось шоломи [Детальніше](https://shop.com/helmet) дуже гарні',
                            'role' => 'assistant',
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
            ], 200),
        ]);

        $agent = $this->makeAgent();
        $events = $this->collectEvents($agent, 'які є тактичні шоломи для захисту', 'test_strip');

        $allText = '';
        foreach ($events as $e) {
            if ($e['type'] === 'chunk') {
                $allText .= $e['data']['text'] ?? '';
            }
        }

        $this->assertStringNotContainsString('https://', $allText);
        $this->assertStringNotContainsString('[Детальніше]', $allText);
    }

    // ───────────────────────────────────────────────
    // SSE endpoint integration
    // ───────────────────────────────────────────────

    public function test_sse_endpoint_returns_streamed_response(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Тестова відповідь',
                            'role' => 'assistant',
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
            ], 200),
        ]);

        \App\Models\WidgetSettings::create([
            'tenant_id' => 2,
            'api_token' => 'test-widget-token',
            'domain' => 'https://test-shop.com',
        ]);

        $response = $this->get('/api/chat/stream?message=привіт&session_id=test_sse_endpoint&token=test-widget-token');

        $response->assertStatus(200);
        $this->assertEquals('text/event-stream; charset=utf-8', $response->headers->get('Content-Type'));
    }
}
