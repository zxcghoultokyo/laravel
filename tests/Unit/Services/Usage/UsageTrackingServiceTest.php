<?php

namespace Tests\Unit\Services\Usage;

use App\Models\Tenant;
use App\Services\Usage\UsageTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression: messages_limit of 0 or null must be treated as unlimited,
 * matching Tenant::canSendMessage() convention. Otherwise trial tenants
 * with a misconfigured messages_limit=0 are locked out of the widget.
 */
class UsageTrackingServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(array $overrides = []): Tenant
    {
        return Tenant::create(array_merge([
            'name' => 'T',
            'slug' => 'slug-'.uniqid(),
            'email' => uniqid().'@t.com',
            'platform' => 'horoshop',
            'status' => Tenant::STATUS_ACTIVE,
            'plan' => 'trial',
            'messages_used' => 0,
            'messages_limit' => null,
        ], $overrides));
    }

    public function test_zero_limit_is_unlimited(): void
    {
        $tenant = $this->makeTenant(['messages_limit' => 0, 'messages_used' => 500]);
        $service = app(UsageTrackingService::class);

        $this->assertFalse($service->hasReachedLimit($tenant));
        $this->assertSame(PHP_INT_MAX, $service->getRemainingMessages($tenant));
    }

    public function test_null_limit_is_unlimited(): void
    {
        // DB schema enforces NOT NULL on messages_limit, but the check must still
        // handle null defensively (e.g. when the model is built without DB persistence).
        $tenant = new Tenant(['messages_limit' => null, 'messages_used' => 999999]);
        $tenant->id = 999;
        $service = app(UsageTrackingService::class);

        $this->assertFalse($service->hasReachedLimit($tenant));
    }

    public function test_enforced_limit_blocks_when_reached(): void
    {
        $tenant = $this->makeTenant(['messages_limit' => 100, 'messages_used' => 100]);
        $service = app(UsageTrackingService::class);

        $this->assertTrue($service->hasReachedLimit($tenant));
        $this->assertSame(0, $service->getRemainingMessages($tenant));
    }

    public function test_enforced_limit_allows_below_cap(): void
    {
        $tenant = $this->makeTenant(['messages_limit' => 100, 'messages_used' => 42]);
        $service = app(UsageTrackingService::class);

        $this->assertFalse($service->hasReachedLimit($tenant));
        $this->assertSame(58, $service->getRemainingMessages($tenant));
    }
}
