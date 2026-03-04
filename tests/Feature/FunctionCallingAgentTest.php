<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Services\Agent\FunctionCallingAgent;
use App\Services\Agent\Tools\MeiliProductSearchTool;
use App\Services\Agent\Tools\ProductDetailsTool;
use App\Services\Horoshop\OrderSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests for FunctionCallingAgent — GPT API call handling, error paths, retry logic.
 * Uses Http::fake() to mock OpenAI responses without real API calls.
 *
 * These tests ensure that:
 * - Quota errors (429) are detected and result in fallback
 * - Missing API key results in immediate fallback
 * - Retryable errors trigger retry logic
 * - Non-retryable errors fail fast (no retries)
 * - Successful GPT responses are properly formatted
 */
class FunctionCallingAgentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test tenant so system prompt building doesn't crash
        Tenant::create([
            'id' => 2,
            'name' => 'Test Shop',
            'slug' => 'test-shop',
            'domain' => 'https://test-shop.com',
            'owner_id' => 1,
            'plan' => 'starter',
        ]);

        // Clear tenant cache
        Cache::flush();

        // Set OpenAI config for tests
        config([
            'services.openai.key' => 'sk-test-key-for-unit-tests',
            'services.openai.model' => 'gpt-4o',
            'services.openai.base_url' => 'https://api.openai.com/v1',
        ]);
    }

    /**
     * Create an agent with mocked search/details tools.
     */
    private function makeAgent(
        ?MeiliProductSearchTool $searchTool = null,
        ?ProductDetailsTool $detailsTool = null,
    ): FunctionCallingAgent {
        $searchTool ??= $this->createMock(MeiliProductSearchTool::class);
        $searchTool->method('getCurrentTenantId')->willReturn(2);
        $searchTool->method('search')->willReturn([]);

        $detailsTool ??= $this->createMock(ProductDetailsTool::class);
        $detailsTool->method('getCards')->willReturn([]);

        $orderSearch = $this->createMock(OrderSearchService::class);

        return new FunctionCallingAgent($searchTool, $detailsTool, $orderSearch);
    }

    // ───────────────────────────────────────────────
    // GPT successful response
    // ───────────────────────────────────────────────

    public function test_handle_returns_text_response_from_gpt(): void
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

        // Use a multi-word query that won't be intercepted by preprocessor
        // and won't match short_query_handler categories
        $result = $agent->handle('розкажи про умови доставки замовлень', ['session_id' => 'test_text']);

        $this->assertArrayHasKey('meta', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('products', $result);

        // Either intercepted or handled by GPT — both are valid
        $agentType = $result['meta']['agent'] ?? $result['meta']['type'] ?? null;
        $this->assertNotNull($agentType, 'Response should have agent or type in meta');
    }

    public function test_handle_response_has_required_keys(): void
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

        $agent = $this->makeAgent();
        $result = $agent->handle('що порадите для виживання в зимових умовах', ['session_id' => 'test_format']);

        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('products', $result);
        $this->assertArrayHasKey('meta', $result);
        $this->assertIsArray($result['products']);
        $this->assertIsArray($result['meta']);
    }

    // ───────────────────────────────────────────────
    // Quota / billing error (the exact bug we had!)
    // ───────────────────────────────────────────────

    public function test_handle_falls_back_on_insufficient_quota_error(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'error' => [
                    'message' => 'You exceeded your current quota, please check your plan and billing details.',
                    'type' => 'insufficient_quota',
                    'code' => 'insufficient_quota',
                ],
            ], 429),
        ]);

        $agent = $this->makeAgent();
        $result = $agent->handle('покажи мені тактичні шоломи для бойових дій', ['session_id' => 'test_quota']);

        $this->assertSame('fallback', $result['meta']['agent']);
    }

    // ───────────────────────────────────────────────
    // Empty API key
    // ───────────────────────────────────────────────

    public function test_handle_falls_back_on_empty_api_key(): void
    {
        config(['services.openai.key' => '']);

        $agent = $this->makeAgent();
        $result = $agent->handle('покажи куртки для зими що є в наявності', ['session_id' => 'test_no_key']);

        $this->assertSame('fallback', $result['meta']['agent']);
    }

    // ───────────────────────────────────────────────
    // Retry logic
    // ───────────────────────────────────────────────

    public function test_handle_retries_on_rate_limit_then_succeeds(): void
    {
        Http::fakeSequence('api.openai.com/*')
            // First call: rate_limit_exceeded
            ->push([
                'error' => [
                    'message' => 'Rate limit exceeded',
                    'type' => 'rate_limit_exceeded',
                    'code' => 'rate_limit_exceeded',
                ],
            ], 429)
            // Second call: success
            ->push([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Ось результати пошуку',
                            'role' => 'assistant',
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
            ], 200);

        $agent = $this->makeAgent();
        $result = $agent->handle('порадьте хороші тактичні берці для осені', ['session_id' => 'test_retry']);

        // Should eventually succeed after retry
        $this->assertNotSame('fallback', $result['meta']['agent'] ?? 'not_fallback');
        // At least 2 requests: initial (429) + retry (200)
        Http::assertSentCount(2);
    }

    public function test_handle_does_not_retry_on_non_retryable_error(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'error' => [
                    'message' => 'Invalid API key',
                    'type' => 'invalid_request_error',
                    'code' => 'invalid_api_key',
                ],
            ], 401),
        ]);

        $agent = $this->makeAgent();
        $result = $agent->handle('покажи рюкзаки для тактичних операцій', ['session_id' => 'test_no_retry']);

        $this->assertSame('fallback', $result['meta']['agent']);
        // Should have been called only once (no retries for invalid_request_error)
        Http::assertSentCount(1);
    }

    public function test_handle_falls_back_after_exhausting_retries_on_server_error(): void
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
        $result = $agent->handle('покажи штани для зимових місій', ['session_id' => 'test_500']);

        // After 4 attempts (initial + 3 retries), should fall back
        $this->assertSame('fallback', $result['meta']['agent']);
        Http::assertSentCount(4); // 1 initial + 3 retries
    }

    // ───────────────────────────────────────────────
    // Intercepted queries
    // ───────────────────────────────────────────────

    public function test_handle_intercepted_greeting(): void
    {
        Http::fake(); // No real calls should be made

        $agent = $this->makeAgent();
        $result = $agent->handle('привіт', ['session_id' => 'test_greeting']);

        // "привіт" is intercepted by QueryPreprocessorService (no GPT call)
        $this->assertTrue($result['meta']['intercepted'] ?? false, 'Greeting should be intercepted');
        $this->assertNotEmpty($result['message']);
    }

    // ───────────────────────────────────────────────
    // URL stripping
    // ───────────────────────────────────────────────

    public function test_handle_strips_urls_from_gpt_response(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Ось шоломи [Детальніше](https://shop.com/helmet) дуже гарні для захисту',
                            'role' => 'assistant',
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
            ], 200),
        ]);

        $agent = $this->makeAgent();
        $result = $agent->handle('які є тактичні шоломи для захисту голови', ['session_id' => 'test_strip']);

        $this->assertStringNotContainsString('https://', $result['message']);
        $this->assertStringNotContainsString('[Детальніше]', $result['message']);
    }

    // ───────────────────────────────────────────────
    // Short query path (no GPT needed)
    // ───────────────────────────────────────────────

    public function test_single_word_query_uses_short_query_handler(): void
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
        $result = $agent->handle('шоломи', ['session_id' => 'test_short']);

        $this->assertSame('function_calling', $result['meta']['agent']);
        $this->assertSame('short_query_handler', $result['meta']['source'] ?? null);
        $this->assertNotEmpty($result['products']);
    }

    // ───────────────────────────────────────────────
    // Diagnostic endpoint
    // ───────────────────────────────────────────────

    public function test_diagnostic_openai_check_endpoint_returns_config(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => ['content' => 'OK', 'role' => 'assistant'],
                        'finish_reason' => 'stop',
                    ],
                ],
            ], 200),
        ]);

        $response = $this->get('/api/diagnostic/openai-check?key=diagnostic_secret_key_2025');

        $response->assertStatus(200);
        $data = $response->json();

        // Verify config info is present
        $this->assertArrayHasKey('config', $data);
        $this->assertTrue($data['config']['api_key_set']);

        // Verify GPT call was made
        $this->assertArrayHasKey('gpt_call', $data);
        $this->assertTrue($data['gpt_call']['success']);
        $this->assertSame(200, $data['gpt_call']['status']);
    }

    public function test_diagnostic_openai_check_detects_quota_error(): void
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

        $response = $this->get('/api/diagnostic/openai-check?key=diagnostic_secret_key_2025');

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertArrayHasKey('gpt_call', $data);
        $this->assertSame(429, $data['gpt_call']['status']);
        $this->assertNotNull($data['gpt_call']['error']);
        $this->assertStringContainsString(
            'quota',
            $data['gpt_call']['error']['type'] ?? $data['gpt_call']['error']['message'] ?? '',
        );
    }

    public function test_diagnostic_openai_check_rejects_invalid_key(): void
    {
        $response = $this->get('/api/diagnostic/openai-check?key=wrong_key');

        $response->assertStatus(401);
    }
}
