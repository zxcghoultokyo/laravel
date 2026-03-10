<?php

namespace Tests\Feature;

use App\Services\Agent\Tools\MeiliProductSearchTool;
use Tests\TestCase;

class AgeCategoryDetectionTest extends TestCase
{
    private \ReflectionMethod $method;

    private MeiliProductSearchTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tool = app(MeiliProductSearchTool::class);
        $this->method = new \ReflectionMethod(MeiliProductSearchTool::class, 'detectAgeCategoryFromQuery');
        $this->method->setAccessible(true);
    }

    public function test_detects_age_3_years(): void
    {
        $result = $this->method->invoke($this->tool, 'іграшки для дитини 3 роки');
        $this->assertNotNull($result);
        $this->assertStringContainsString('дошкільнятам', $result);
    }

    public function test_detects_age_1_year(): void
    {
        $result = $this->method->invoke($this->tool, 'іграшки для дитини 1 рік');
        $this->assertNotNull($result);
        $this->assertStringContainsString('тодлерам', $result);
    }

    public function test_detects_baby_keywords(): void
    {
        $result = $this->method->invoke($this->tool, 'іграшки для немовляти');
        $this->assertNotNull($result);
        $this->assertStringContainsString('малюкам', $result);
    }

    public function test_detects_toddler_age_2(): void
    {
        $result = $this->method->invoke($this->tool, 'що підійде для 2 років');
        $this->assertNotNull($result);
        $this->assertStringContainsString('тодлерам', $result);
    }

    public function test_detects_preschool_age_5(): void
    {
        $result = $this->method->invoke($this->tool, 'подарунок для дитини 5 років');
        $this->assertNotNull($result);
        $this->assertStringContainsString('дошкільнятам', $result);
    }

    public function test_no_detection_for_generic_query(): void
    {
        $result = $this->method->invoke($this->tool, 'іграшки');
        $this->assertNull($result);
    }

    public function test_detects_school_age(): void
    {
        $result = $this->method->invoke($this->tool, 'для дитини 10 років');
        $this->assertEquals('школярам', $result);
    }

    public function test_detects_malyuk_keyword(): void
    {
        $result = $this->method->invoke($this->tool, 'іграшки для малюка');
        $this->assertNotNull($result);
        $this->assertStringContainsString('малюкам', $result);
    }

    public function test_search_products_tool_has_category_param(): void
    {
        $searchTool = $this->createMock(MeiliProductSearchTool::class);
        $detailsTool = $this->createMock(\App\Services\Agent\Tools\ProductDetailsTool::class);
        $orderSearch = $this->createMock(\App\Services\Horoshop\OrderSearchService::class);

        $agent = new \Tests\Feature\TestableBaseAgent($searchTool, $detailsTool, $orderSearch);
        $tools = $agent->exposeGetTools();

        $searchFunc = collect($tools)->first(fn ($t) => $t['function']['name'] === 'search_products');
        $this->assertArrayHasKey('category', $searchFunc['function']['parameters']['properties']);
    }

    public function test_prompt_contains_age_filtering_rules(): void
    {
        $service = app(\App\Services\Ai\PromptModulesService::class);
        $searchModule = $service->getSearchModule();

        $this->assertStringContainsString('ВІКОВА ФІЛЬТРАЦІЯ', $searchModule);
        $this->assertStringContainsString('category', $searchModule);
    }

    public function test_normalize_category_strips_gpt_format(): void
    {
        $normalizeMethod = new \ReflectionMethod(MeiliProductSearchTool::class, 'normalizeCategoryFilter');
        $normalizeMethod->setAccessible(true);

        // GPT may pass categories with numbers/parentheses that don't match actual data
        $this->assertEquals('дошкільнятам', $normalizeMethod->invoke($this->tool, 'дошкільнятам (3-6)'));
        $this->assertEquals('тодлерам', $normalizeMethod->invoke($this->tool, 'тодлерам (1-3)'));
        $this->assertEquals('малюкам', $normalizeMethod->invoke($this->tool, 'малюкам (0-1)'));
        $this->assertEquals('школярам', $normalizeMethod->invoke($this->tool, 'школярам'));
    }

    public function test_normalize_category_preserves_non_age_categories(): void
    {
        $normalizeMethod = new \ReflectionMethod(MeiliProductSearchTool::class, 'normalizeCategoryFilter');
        $normalizeMethod->setAccessible(true);

        // Non-age categories should pass through unchanged
        $this->assertEquals('меблі та організація', $normalizeMethod->invoke($this->tool, 'меблі та організація'));
    }

    public function test_normalized_keyword_matches_real_category_path(): void
    {
        // Simulate the actual matching logic from MeiliProductSearchTool
        $realCategoryPaths = [
            'іграшки/малюкам 0 – 1',
            'іграшки/тодлерам 1 – 3',
            'іграшки/дошкільнятам 3 – 7',
        ];

        $normalizeMethod = new \ReflectionMethod(MeiliProductSearchTool::class, 'normalizeCategoryFilter');
        $normalizeMethod->setAccessible(true);

        // GPT passes "дошкільнятам (3-6)" → normalized to "дошкільнятам"
        $normalized = $normalizeMethod->invoke($this->tool, 'дошкільнятам (3-6)');
        $this->assertTrue(str_contains('іграшки/дошкільнятам 3 – 7', $normalized));

        // GPT passes "тодлерам (1-3)" → normalized to "тодлерам"
        $normalized = $normalizeMethod->invoke($this->tool, 'тодлерам (1-3)');
        $this->assertTrue(str_contains('іграшки/тодлерам 1 – 3', $normalized));
    }

    public function test_adjacent_lower_category(): void
    {
        $this->assertEquals('дошкільнятам', $this->tool->getAdjacentLowerCategory('школярам'));
        $this->assertEquals('тодлерам', $this->tool->getAdjacentLowerCategory('дошкільнятам'));
        $this->assertEquals('малюкам', $this->tool->getAdjacentLowerCategory('тодлерам'));
        $this->assertNull($this->tool->getAdjacentLowerCategory('малюкам'));
    }

    public function test_adjacent_upper_category(): void
    {
        $this->assertEquals('тодлерам', $this->tool->getAdjacentUpperCategory('малюкам'));
        $this->assertEquals('дошкільнятам', $this->tool->getAdjacentUpperCategory('тодлерам'));
        $this->assertEquals('школярам', $this->tool->getAdjacentUpperCategory('дошкільнятам'));
        $this->assertNull($this->tool->getAdjacentUpperCategory('школярам'));
    }

    public function test_boundary_age_detection(): void
    {
        // Ages on category boundaries
        $this->assertTrue($this->tool->isBoundaryAge('іграшки для дитини 1 рік'));
        $this->assertTrue($this->tool->isBoundaryAge('подарунок на 3 роки'));
        $this->assertTrue($this->tool->isBoundaryAge('що купити на 7 років'));

        // Ages NOT on boundaries
        $this->assertFalse($this->tool->isBoundaryAge('іграшки для 2 років'));
        $this->assertFalse($this->tool->isBoundaryAge('подарунок на 5 років'));
        $this->assertFalse($this->tool->isBoundaryAge('для малюка'));
        $this->assertFalse($this->tool->isBoundaryAge('покажи сортери'));
    }
}
