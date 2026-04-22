<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * End-to-end tests for POST /api/chat — ensures middleware stack (tenant resolution,
 * plan enforcement, CORS, rate limit) and controller return expected payloads.
 *
 * Uses Http::fake() so OpenAI is never called.
 */
class ChatApiEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'services.openai.key' => 'sk-test-key',
            'services.openai.model' => 'gpt-4o',
            'services.openai.base_url' => 'https://api.openai.com/v1',
        ]);
    }

    private function makeTenant(array $overrides = []): Tenant
    {
        return Tenant::create(array_merge([
            'name' => 'Test Shop',
            'slug' => 'test-shop-'.uniqid(),
            'domain' => 'https://test-shop.com',
            'owner_id' => 1,
            'plan' => 'trial',
            'status' => 'active',
            'trial_ends_at' => now()->addDays(14),
            'messages_limit' => 2000,
            'messages_used' => 0,
        ], $overrides));
    }

    public function test_chat_endpoint_returns_200_for_active_trial_tenant(): void
    {
        $tenant = $this->makeTenant();

        // Mock OpenAI — return a simple text response (no tool calls).
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Привіт! Чим можу допомогти?',
                    ],
                ]],
            ], 200),
        ]);

        $response = $this->postJson('/api/chat', [
            'message' => 'привіт',
            'session_id' => 'test_session_'.uniqid(),
            'token' => $tenant->slug,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['type', 'session_id']);
    }

    public function test_chat_endpoint_blocks_expired_trial(): void
    {
        $tenant = $this->makeTenant([
            'trial_ends_at' => now()->subDay(),
            'plan' => 'trial',
            'plan_expires_at' => null,
        ]);

        $response = $this->postJson('/api/chat', [
            'message' => 'привіт',
            'session_id' => 'expired_session_'.uniqid(),
            'token' => $tenant->slug,
        ]);

        // ResolveTenantMiddleware returns 403, CheckTenantLimits returns 402.
        // Either way, the request must be blocked.
        $this->assertContains($response->status(), [402, 403], 'Expired trial must be blocked');
    }

    public function test_chat_endpoint_blocks_suspended_tenant(): void
    {
        $tenant = $this->makeTenant([
            'status' => 'suspended',
        ]);

        $response = $this->postJson('/api/chat', [
            'message' => 'привіт',
            'session_id' => 'suspended_session_'.uniqid(),
            'token' => $tenant->slug,
        ]);

        $this->assertContains($response->status(), [402, 403], 'Suspended tenant must be blocked');
    }

    public function test_chat_endpoint_without_token_does_not_crash(): void
    {
        $response = $this->postJson('/api/chat', [
            'message' => 'привіт',
            'session_id' => 'no_token_'.uniqid(),
        ]);

        // Must not crash with 500. Middleware either lets through (tenant optional)
        // or returns a client error.
        $this->assertLessThan(500, $response->status(), 'No-token request must not 500');
    }
}
