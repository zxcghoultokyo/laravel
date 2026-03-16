<?php

namespace Tests\Feature;

use App\Services\Agent\BaseAgent;
use App\Services\Agent\Tools\MeiliProductSearchTool;
use App\Services\Agent\Tools\ProductDetailsTool;
use App\Services\Horoshop\OrderSearchService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AgeFollowUpContextTest extends TestCase
{
    /**
     * Concrete test implementation of BaseAgent for testing protected methods.
     */
    private function makeTestAgent(): BaseAgent
    {
        $searchTool = $this->createMock(MeiliProductSearchTool::class);
        $detailsTool = $this->createMock(ProductDetailsTool::class);
        $orderService = $this->createMock(OrderSearchService::class);

        return new class($searchTool, $detailsTool, $orderService) extends BaseAgent
        {
            public function exposeSaveCtx(?string $sessionId, string $msg, string $src): void
            {
                $this->saveLastProductContext($sessionId, $msg, $src);
            }

            public function exposeLoadCtx(?string $sessionId): ?array
            {
                return $this->loadLastProductContext($sessionId);
            }

            public function exposeFollowUp(string $msg): bool
            {
                return $this->isFollowUpMessage($msg);
            }
        };
    }

    public function test_save_and_load_product_context(): void
    {
        $agent = $this->makeTestAgent();
        $sessionId = 'test_session_'.uniqid();

        $agent->exposeSaveCtx($sessionId, 'подарунок на 1 рік', 'age_query_handler');
        $ctx = $agent->exposeLoadCtx($sessionId);

        $this->assertNotNull($ctx);
        $this->assertEquals('подарунок на 1 рік', $ctx['original_message']);
        $this->assertEquals('age_query_handler', $ctx['source']);

        Cache::forget("last_product_ctx_{$sessionId}");
    }

    public function test_load_returns_null_without_save(): void
    {
        $agent = $this->makeTestAgent();
        $this->assertNull($agent->exposeLoadCtx('nonexistent_session'));
    }

    public function test_load_returns_null_for_null_session(): void
    {
        $agent = $this->makeTestAgent();
        $this->assertNull($agent->exposeLoadCtx(null));
    }

    public function test_save_does_not_crash_with_null_session(): void
    {
        $agent = $this->makeTestAgent();
        $agent->exposeSaveCtx(null, 'test', 'test');
        $this->assertTrue(true);
    }

    public function test_is_follow_up_detects_price_patterns(): void
    {
        $agent = $this->makeTestAgent();

        $this->assertTrue($agent->exposeFollowUp('дорожче'));
        $this->assertTrue($agent->exposeFollowUp('дешевше'));
        $this->assertTrue($agent->exposeFollowUp('покажи дорожче'));
        $this->assertTrue($agent->exposeFollowUp('є дешевший варіант?'));
    }

    public function test_is_follow_up_detects_more_patterns(): void
    {
        $agent = $this->makeTestAgent();

        $this->assertTrue($agent->exposeFollowUp('ще покажи'));
        $this->assertTrue($agent->exposeFollowUp('інші варіанти'));
        $this->assertTrue($agent->exposeFollowUp('аналоги'));
        $this->assertTrue($agent->exposeFollowUp('подібні'));
        $this->assertTrue($agent->exposeFollowUp('такі ж'));
        $this->assertTrue($agent->exposeFollowUp('більше варіантів'));
    }

    public function test_is_follow_up_rejects_normal_queries(): void
    {
        $agent = $this->makeTestAgent();

        $this->assertFalse($agent->exposeFollowUp('подарунок на 1 рік'));
        $this->assertFalse($agent->exposeFollowUp('шоломи'));
        $this->assertFalse($agent->exposeFollowUp('покажи куртки'));
        $this->assertFalse($agent->exposeFollowUp('що на весну'));
    }
}
