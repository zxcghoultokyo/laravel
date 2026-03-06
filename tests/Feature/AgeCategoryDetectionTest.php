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

    public function test_no_detection_for_school_age(): void
    {
        $result = $this->method->invoke($this->tool, 'для дитини 10 років');
        $this->assertNull($result);
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
}
