<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConversionFunnelTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = DB::table('tenants')->insertGetId([
            'name' => 'Test Funnel Store',
            'slug' => 'test-funnel',
            'domain' => 'test-funnel.com',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('widget_settings')->insert([
            'tenant_id' => $this->tenantId,
            'domain' => 'test-funnel.com',
            'api_token' => 'test_token_funnel',
            'primary_color' => '#000000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Test that checkout count never exceeds add_to_cart count in funnel.
     * Orders with had_chat=true but no corresponding add_to_cart event
     * should NOT appear in the funnel "Замовлення" stage.
     */
    public function test_checkout_does_not_exceed_add_to_cart(): void
    {
        $startDate = now()->subDays(14)->startOfDay();
        $tenantId = $this->tenantId;

        // Create add_to_cart event for session 1 (chat-attributed)
        DB::table('chat_events')->insert([
            'session_id' => 'funnel_test_session_1',
            'tenant_id' => $tenantId,
            'event_type' => 'add_to_cart',
            'metadata' => json_encode(['had_chat_conversation' => true]),
            'created_at' => now()->subDays(2),
        ]);

        // Create order linked to session 1 (should count in funnel)
        DB::table('orders')->insert([
            'order_id' => 'ORD-001',
            'tenant_id' => $tenantId,
            'session_id' => 'funnel_test_session_1',
            'had_chat' => true,
            'total_sum' => 500,
            'status_code' => 'new',
            'ordered_at' => now()->subDays(2),
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        // Create order linked to session 2 with had_chat=true
        // but NO add_to_cart event — this should NOT count in funnel
        DB::table('orders')->insert([
            'order_id' => 'ORD-002',
            'tenant_id' => $tenantId,
            'session_id' => 'funnel_test_session_2',
            'had_chat' => true,
            'total_sum' => 1200,
            'status_code' => 'new',
            'ordered_at' => now()->subDays(1),
            'created_at' => now()->subDays(1),
            'updated_at' => now()->subDays(1),
        ]);

        // Create order without any chat — should also NOT count
        DB::table('orders')->insert([
            'order_id' => 'ORD-003',
            'tenant_id' => $tenantId,
            'session_id' => null,
            'had_chat' => false,
            'total_sum' => 300,
            'status_code' => 'new',
            'ordered_at' => now()->subDays(1),
            'created_at' => now()->subDays(1),
            'updated_at' => now()->subDays(1),
        ]);

        // Calculate funnel the same way TenantDashboard does
        $cartSessionIds = DB::table('chat_events')
            ->where('event_type', 'add_to_cart')
            ->where('created_at', '>=', $startDate)
            ->where('tenant_id', $tenantId)
            ->distinct()
            ->pluck('session_id')
            ->toArray();

        $this->assertCount(1, $cartSessionIds);
        $this->assertContains('funnel_test_session_1', $cartSessionIds);

        $checkoutCount = 0;
        if (! empty($cartSessionIds)) {
            $checkoutCount = DB::table('orders')
                ->where('created_at', '>=', $startDate)
                ->where('had_chat', true)
                ->whereIn('session_id', $cartSessionIds)
                ->count();
        }

        // add_to_cart = 1, so checkout should be <= 1 (not 2!)
        $addToCartCount = DB::table('chat_events')
            ->where('event_type', 'add_to_cart')
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $startDate)
            ->count();

        $this->assertEquals(1, $addToCartCount);
        $this->assertEquals(1, $checkoutCount);
        $this->assertLessThanOrEqual($addToCartCount, $checkoutCount, 'Checkout count must not exceed add_to_cart count');
    }

    /**
     * Test that checkout is zero when there are no add_to_cart events,
     * even if there are orders with had_chat=true.
     */
    public function test_checkout_is_zero_without_cart_events(): void
    {
        $startDate = now()->subDays(14)->startOfDay();
        $tenantId = $this->tenantId;

        // Create order with had_chat=true but no add_to_cart event
        DB::table('orders')->insert([
            'order_id' => 'ORD-NO-CART',
            'tenant_id' => $tenantId,
            'session_id' => 'funnel_no_cart_session',
            'had_chat' => true,
            'total_sum' => 999,
            'status_code' => 'new',
            'ordered_at' => now()->subDays(3),
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(3),
        ]);

        $cartSessionIds = DB::table('chat_events')
            ->where('event_type', 'add_to_cart')
            ->where('created_at', '>=', $startDate)
            ->where('tenant_id', $tenantId)
            ->distinct()
            ->pluck('session_id')
            ->toArray();

        $this->assertEmpty($cartSessionIds);

        // With empty cart sessions, checkout should be 0
        $checkoutCount = 0;
        if (! empty($cartSessionIds)) {
            $checkoutCount = DB::table('orders')
                ->where('created_at', '>=', $startDate)
                ->where('had_chat', true)
                ->whereIn('session_id', $cartSessionIds)
                ->count();
        }

        $this->assertEquals(0, $checkoutCount);
    }

    /**
     * Test multiple add_to_cart events from same session still lead to 1 order.
     */
    public function test_multiple_cart_events_same_session(): void
    {
        $startDate = now()->subDays(14)->startOfDay();
        $tenantId = $this->tenantId;

        // 3 add_to_cart events from same session
        for ($i = 0; $i < 3; $i++) {
            DB::table('chat_events')->insert([
                'session_id' => 'multi_cart_session',
                'tenant_id' => $tenantId,
                'event_type' => 'add_to_cart',
                'metadata' => json_encode(['had_chat_conversation' => true]),
                'created_at' => now()->subDays(1),
            ]);
        }

        // 1 order for this session
        DB::table('orders')->insert([
            'order_id' => 'ORD-MULTI',
            'tenant_id' => $tenantId,
            'session_id' => 'multi_cart_session',
            'had_chat' => true,
            'total_sum' => 2500,
            'status_code' => 'new',
            'ordered_at' => now()->subDays(1),
            'created_at' => now()->subDays(1),
            'updated_at' => now()->subDays(1),
        ]);

        $cartSessionIds = DB::table('chat_events')
            ->where('event_type', 'add_to_cart')
            ->where('created_at', '>=', $startDate)
            ->where('tenant_id', $tenantId)
            ->distinct()
            ->pluck('session_id')
            ->toArray();

        $addToCartCount = DB::table('chat_events')
            ->where('event_type', 'add_to_cart')
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $startDate)
            ->count();

        $checkoutCount = DB::table('orders')
            ->where('created_at', '>=', $startDate)
            ->where('had_chat', true)
            ->whereIn('session_id', $cartSessionIds)
            ->count();

        $this->assertEquals(3, $addToCartCount, 'All 3 cart events counted');
        $this->assertEquals(1, $checkoutCount, 'Only 1 order');
        $this->assertLessThanOrEqual($addToCartCount, $checkoutCount);
    }
}
