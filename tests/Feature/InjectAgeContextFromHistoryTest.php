<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Services\Agent\BaseAgent;
use App\Services\Agent\Tools\MeiliProductSearchTool;
use App\Services\Agent\Tools\ProductDetailsTool;
use App\Services\Horoshop\OrderSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InjectAgeContextFromHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgent(): BaseAgent
    {
        $detailsTool = $this->createMock(ProductDetailsTool::class);
        $orderService = $this->createMock(OrderSearchService::class);
        $searchTool = app(MeiliProductSearchTool::class);

        return new class($searchTool, $detailsTool, $orderService) extends BaseAgent
        {
            public function exposeInject(array $args, ?string $sessionId, string $msg): array
            {
                return $this->injectAgeContextFromHistory($args, $sessionId, $msg);
            }

            public function exposeDetect(?string $sessionId): array
            {
                return $this->detectAgeContextFromHistory($sessionId);
            }
        };
    }

    public function test_injects_age_from_current_message_with_rochok(): void
    {
        $agent = $this->makeAgent();

        $args = $agent->exposeInject(['query' => 'іграшки'], null, 'щось дитинці на рочок');

        $this->assertSame('тодлерам', $args['category'] ?? null);
        $this->assertSame(0, $args['min_age_months'] ?? null);
        $this->assertSame(36, $args['max_age_months'] ?? null);
    }

    public function test_does_not_override_gpt_supplied_args(): void
    {
        $agent = $this->makeAgent();

        $args = $agent->exposeInject(
            ['query' => 'іграшки', 'category' => 'школярам', 'min_age_months' => 84],
            null,
            'щось дитинці на рочок'
        );

        $this->assertSame('школярам', $args['category']);
        $this->assertSame(84, $args['min_age_months']);
    }

    public function test_no_op_when_no_age_anywhere(): void
    {
        $agent = $this->makeAgent();

        $args = $agent->exposeInject(['query' => 'покажи ще'], null, 'покажи ще');

        $this->assertArrayNotHasKey('min_age_months', $args);
        $this->assertArrayNotHasKey('category', $args);
    }

    public function test_carries_age_from_history_when_current_message_has_no_age(): void
    {
        $agent = $this->makeAgent();
        $sessionId = 'sess_'.uniqid();

        $session = ChatSession::create([
            'session_id' => $sessionId,
            'tenant_id' => 20,
        ]);
        ChatMessage::create([
            'chat_session_id' => $session->id,
            'role' => 'user',
            'content' => 'підкажи щось дитинці на рочок',
        ]);
        ChatMessage::create([
            'chat_session_id' => $session->id,
            'role' => 'assistant',
            'content' => 'Ось музичні інструменти для малюка',
        ]);

        $args = $agent->exposeInject(
            ['query' => 'комплекти на подарунок'],
            $sessionId,
            'а можливо є якісь комплекти на подарунок?'
        );

        $this->assertSame('тодлерам', $args['category'] ?? null, 'category should be carried from history');
        $this->assertSame(0, $args['min_age_months'] ?? null);
        $this->assertSame(36, $args['max_age_months'] ?? null);
    }

    public function test_detect_returns_nulls_for_missing_session(): void
    {
        $agent = $this->makeAgent();
        $result = $agent->exposeDetect(null);
        $this->assertNull($result['months']);
        $this->assertNull($result['category']);
    }
}
