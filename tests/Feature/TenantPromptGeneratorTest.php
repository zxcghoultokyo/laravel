<?php

namespace Tests\Feature;

use App\Models\PromptPreset;
use App\Models\Tenant;
use App\Services\Ai\TenantPromptGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantPromptGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private TenantPromptGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new TenantPromptGenerator;
    }

    public function test_build_prompt_uses_modules_service_core(): void
    {
        $analysis = $this->makeAnalysis();
        $prompt = $this->generator->buildPrompt($analysis);

        // Must contain PromptModulesService core rules
        $this->assertStringContainsString('ЗАВЖДИ шукай через search_products()', $prompt);
        $this->assertStringContainsString('МАКСИМУМ 3 товари', $prompt);
        $this->assertStringContainsString('ПОСИЛАННЯ — ЗАБОРОНЕНО', $prompt);
    }

    public function test_build_prompt_contains_store_profile(): void
    {
        $analysis = $this->makeAnalysis([
            'store_name' => 'Тест Магазин',
            'brands' => ['Nike' => 10, 'Adidas' => 5],
            'in_stock_count' => 500,
            'price_min' => 100,
            'price_max' => 5000,
            'price_avg' => 1200,
        ]);

        $prompt = $this->generator->buildPrompt($analysis);

        $this->assertStringContainsString('ПРОФІЛЬ МАГАЗИНУ', $prompt);
        $this->assertStringContainsString('Nike', $prompt);
        $this->assertStringContainsString('500', $prompt);
        $this->assertStringContainsString('Тест Магазин', $prompt);
    }

    public function test_build_prompt_includes_age_for_children_store(): void
    {
        $analysis = $this->makeAnalysis(['has_age_categories' => true]);
        $prompt = $this->generator->buildPrompt($analysis);

        $this->assertStringContainsString('ВІКОВА ФІЛЬТРАЦІЯ', $prompt);
        $this->assertStringNotContainsString('НЕ питай про вік дитини', $prompt);
    }

    public function test_build_prompt_excludes_age_for_non_children_store(): void
    {
        $analysis = $this->makeAnalysis(['has_age_categories' => false]);
        $prompt = $this->generator->buildPrompt($analysis);

        $this->assertStringNotContainsString('ВІКОВА ФІЛЬТРАЦІЯ', $prompt);
        $this->assertStringContainsString('НЕ питай про вік дитини', $prompt);
    }

    public function test_build_prompt_includes_follow_up(): void
    {
        $analysis = $this->makeAnalysis();
        $prompt = $this->generator->buildPrompt($analysis);

        $this->assertStringContainsString('FOLLOW-UP', $prompt);
        $this->assertStringContainsString('exclude_shown', $prompt);
    }

    public function test_build_prompt_includes_search_examples(): void
    {
        $analysis = $this->makeAnalysis([
            'top_level_categories' => ['Одяг' => 50, 'Взуття' => 30],
        ]);

        $prompt = $this->generator->buildPrompt($analysis);
        $this->assertStringContainsString('ПРИКЛАДИ ПОШУКУ', $prompt);
        $this->assertStringContainsString('одяг', $prompt);
    }

    public function test_save_as_preset_creates_default_when_no_custom(): void
    {
        $tenant = Tenant::create(['name' => 'TestStore', 'slug' => 'teststore', 'email' => 'test@test.com']);

        $result = $this->generator->generate($tenant->id);

        $this->assertNotNull($result['preset_id']);
        $preset = PromptPreset::withoutGlobalScopes()->find($result['preset_id']);
        $this->assertTrue($preset->is_default);
        $this->assertTrue($preset->is_active);
        $this->assertEquals("auto-generated-{$tenant->id}", $preset->slug);
    }

    public function test_save_as_preset_does_not_overwrite_custom_default(): void
    {
        $tenant = Tenant::create(['name' => 'CustomStore', 'slug' => 'customstore', 'email' => 'custom@test.com']);

        // Create a custom is_default=true preset (simulating admin-created)
        $customPreset = PromptPreset::create([
            'tenant_id' => $tenant->id,
            'name' => 'Кастомний промпт',
            'slug' => 'custom-default',
            'system_prompt' => 'Ти — кастомний консультант.',
            'is_default' => true,
            'is_active' => true,
            'priority' => 50,
        ]);

        $result = $this->generator->generate($tenant->id);

        // Custom preset must remain default and active
        $customPreset->refresh();
        $this->assertTrue($customPreset->is_default);
        $this->assertTrue($customPreset->is_active);

        // Auto-generated must be saved as inactive backup
        $autoPreset = PromptPreset::withoutGlobalScopes()->find($result['preset_id']);
        $this->assertFalse($autoPreset->is_default);
        $this->assertFalse($autoPreset->is_active);
        $this->assertStringContainsString('auto-generated', $autoPreset->slug);
    }

    public function test_save_as_preset_overwrites_own_auto_generated(): void
    {
        $tenant = Tenant::create(['name' => 'AutoStore', 'slug' => 'autostore', 'email' => 'auto@test.com']);

        // First generation
        $result1 = $this->generator->generate($tenant->id);
        $presetId1 = $result1['preset_id'];

        // Second generation should update, not create duplicate
        $result2 = $this->generator->generate($tenant->id);
        $presetId2 = $result2['preset_id'];

        $this->assertEquals($presetId1, $presetId2);

        // Still default and active
        $preset = PromptPreset::withoutGlobalScopes()->find($presetId2);
        $this->assertTrue($preset->is_default);
        $this->assertTrue($preset->is_active);
    }

    public function test_dry_run_does_not_save(): void
    {
        $tenant = Tenant::create(['name' => 'DryRunStore', 'slug' => 'dryrunstore', 'email' => 'dryrun@test.com']);

        $result = $this->generator->generate($tenant->id, dryRun: true);

        $this->assertNull($result['preset_id']);
        $this->assertGreaterThan(0, $result['prompt_length']);
        $this->assertCount(0, PromptPreset::withoutGlobalScopes()->where('tenant_id', $tenant->id)->get());
    }

    private function makeAnalysis(array $overrides = []): array
    {
        return array_merge([
            'tenant_id' => 1,
            'store_name' => 'Тестовий Магазин',
            'domain' => 'test.com',
            'total_products' => 100,
            'in_stock_count' => 80,
            'price_min' => 200,
            'price_max' => 10000,
            'price_avg' => 2500,
            'top_level_categories' => ['Категорія1' => 40, 'Категорія2' => 30],
            'categories' => ['Категорія1 > Суб1' => 20, 'Категорія2 > Суб2' => 15],
            'brands' => ['Brand1' => 20, 'Brand2' => 10],
            'has_age_categories' => false,
            'sample_titles' => ['Товар тестовий 1', 'Товар тестовий 2'],
        ], $overrides);
    }
}
