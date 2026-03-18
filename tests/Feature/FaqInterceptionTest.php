<?php

namespace Tests\Feature;

use App\Models\PromptPreset;
use App\Models\Tenant;
use App\Services\Ai\PromptPresetService;
use App\Services\Search\QueryPreprocessorService;
use App\Services\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FaqInterceptionTest extends TestCase
{
    use RefreshDatabase;

    private function createTenant(array $attrs = []): Tenant
    {
        return Tenant::create(array_merge([
            'name' => 'TestStore',
            'slug' => 'teststore-'.uniqid(),
            'email' => 'test'.uniqid().'@test.com',
        ], $attrs));
    }

    /**
     * FAQ should be intercepted when tenant has NO presets (legacy behavior).
     */
    public function test_faq_intercepted_when_tenant_has_no_presets(): void
    {
        $tenant = $this->createTenant();
        app(TenantContext::class)->setTenantId($tenant->id);

        $service = app(QueryPreprocessorService::class);
        $result = $service->preprocess('як оплатити?');

        $this->assertTrue($result['intercepted']);
        $this->assertEquals('faq', $result['response_type']);
        $this->assertStringContainsString('банківською карткою', $result['response']);
    }

    /**
     * FAQ should NOT be intercepted when tenant HAS active presets.
     * Instead, GPT gets the query and uses tenant-specific FAQ from the preset.
     */
    public function test_faq_not_intercepted_when_tenant_has_presets(): void
    {
        $tenant = $this->createTenant();
        app(TenantContext::class)->setTenantId($tenant->id);

        PromptPreset::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Base Preset',
            'slug' => 'test-base',
            'is_default' => true,
            'is_active' => true,
            'priority' => 50,
            'system_prompt' => 'Ти — консультант магазину. ОПЛАТА: карткою 7000 через Пакунок малюка.',
        ]);

        app(PromptPresetService::class)->clearCache($tenant->id);

        $service = app(QueryPreprocessorService::class);
        $result = $service->preprocess('як оплатити?');

        $this->assertFalse($result['intercepted']);
        $this->assertNull($result['response_type']);
    }

    /**
     * Greetings should still be intercepted regardless of presets.
     */
    public function test_greetings_still_intercepted_with_presets(): void
    {
        $tenant = $this->createTenant();
        app(TenantContext::class)->setTenantId($tenant->id);

        PromptPreset::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Preset',
            'slug' => 'test-greeting',
            'is_default' => true,
            'is_active' => true,
            'priority' => 50,
            'system_prompt' => 'Test prompt.',
        ]);

        app(PromptPresetService::class)->clearCache($tenant->id);

        $service = app(QueryPreprocessorService::class);
        $result = $service->preprocess('привіт');

        $this->assertTrue($result['intercepted']);
        $this->assertEquals('greeting', $result['response_type']);
    }

    /**
     * Brand detection and slang should still work with presets.
     */
    public function test_brand_detection_works_with_presets(): void
    {
        $tenant = $this->createTenant();
        app(TenantContext::class)->setTenantId($tenant->id);

        PromptPreset::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Preset',
            'slug' => 'test-brand',
            'is_default' => true,
            'is_active' => true,
            'priority' => 50,
            'system_prompt' => 'Test prompt.',
        ]);

        app(PromptPresetService::class)->clearCache($tenant->id);

        $service = app(QueryPreprocessorService::class);
        $result = $service->preprocess('покажи опс кор');

        $this->assertFalse($result['intercepted']);
        $this->assertNotNull($result['detected_brand'] ?? $result['detected_slang']);
    }
}
