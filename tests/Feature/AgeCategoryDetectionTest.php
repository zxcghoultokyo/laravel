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

    public function test_extract_age_months_from_query(): void
    {
        // Years
        $this->assertEquals(12, $this->tool->extractAgeMonthsFromQuery('подарунок на 1 рік'));
        $this->assertEquals(36, $this->tool->extractAgeMonthsFromQuery('іграшки для 3 роки'));
        $this->assertEquals(84, $this->tool->extractAgeMonthsFromQuery('що купити на 7 років'));
        $this->assertEquals(24, $this->tool->extractAgeMonthsFromQuery('для дитини 2 років'));

        // Months
        $this->assertEquals(6, $this->tool->extractAgeMonthsFromQuery('іграшки для 6 місяців'));
        $this->assertEquals(8, $this->tool->extractAgeMonthsFromQuery('подарунок 8 міс'));

        // No age
        $this->assertNull($this->tool->extractAgeMonthsFromQuery('покажи сортери'));
        $this->assertNull($this->tool->extractAgeMonthsFromQuery('іграшки'));
    }

    public function test_product_raw_extractor_age_text(): void
    {
        // Bavkatoys format: characteristics.dljaDtok.ua
        $raw = [
            'characteristics' => [
                'dljaDtok' => [
                    'ua' => 'З 14 місяців+',
                    'en' => '14 months +',
                ],
            ],
        ];
        $this->assertEquals('З 14 місяців+', \App\Support\ProductRawExtractor::ageText($raw));

        // Empty characteristics
        $this->assertEquals('', \App\Support\ProductRawExtractor::ageText([]));

        // Alternative key: vik
        $raw2 = [
            'characteristics' => [
                'vik' => [
                    'ua' => 'від 3 років',
                ],
            ],
        ];
        $this->assertEquals('від 3 років', \App\Support\ProductRawExtractor::ageText($raw2));
    }

    public function test_product_raw_extractor_parse_age_months(): void
    {
        // "З 14 місяців+" → min=14, max=null
        $result = \App\Support\ProductRawExtractor::parseAgeMonths('З 14 місяців+');
        $this->assertEquals(14, $result['min_months']);
        $this->assertNull($result['max_months']);

        // "від 3 місяців, орієнтовно до року" → min=3, max=12
        $result = \App\Support\ProductRawExtractor::parseAgeMonths('від 3 місяців, орієнтовно до року');
        $this->assertEquals(3, $result['min_months']);
        $this->assertEquals(12, $result['max_months']);

        // "5 місяців + (орієнтовно до 14 місяців)" → min=5, max=14
        $result = \App\Support\ProductRawExtractor::parseAgeMonths('5 місяців + (орієнтовно до 14 місяців)');
        $this->assertEquals(5, $result['min_months']);
        $this->assertEquals(14, $result['max_months']);

        // "від 2-ох років" → min=24, max=null
        $result = \App\Support\ProductRawExtractor::parseAgeMonths('від 2-ох років');
        $this->assertEquals(24, $result['min_months']);
        $this->assertNull($result['max_months']);

        // Empty → null/null
        $result = \App\Support\ProductRawExtractor::parseAgeMonths('');
        $this->assertNull($result['min_months']);
        $this->assertNull($result['max_months']);

        // "3 місяці+" → min=3, max=null
        $result = \App\Support\ProductRawExtractor::parseAgeMonths('3 місяці+');
        $this->assertEquals(3, $result['min_months']);
        $this->assertNull($result['max_months']);
    }

    public function test_extract_min_age_from_category_path(): void
    {
        // Standard bavkatoys categories
        $this->assertEquals(0, $this->tool->extractMinAgeFromCategoryPath('ІГРАШКИ/МАЛЮКАМ 0 – 1'));
        $this->assertEquals(0, $this->tool->extractMinAgeFromCategoryPath('ІГРАШКИ/МАЛЮКАМ 0 – 1 '));
        $this->assertEquals(12, $this->tool->extractMinAgeFromCategoryPath('ІГРАШКИ/ТОДЛЕРАМ 1 – 3'));
        $this->assertEquals(36, $this->tool->extractMinAgeFromCategoryPath('ІГРАШКИ/ДОШКІЛЬНЯТАМ 3 – 7'));
        $this->assertEquals(84, $this->tool->extractMinAgeFromCategoryPath('ІГРАШКИ/ШКОЛЯРАМ 7 – 14'));

        // With dash instead of en-dash
        $this->assertEquals(36, $this->tool->extractMinAgeFromCategoryPath('ІГРАШКИ/ДОШКІЛЬНЯТАМ 3-7'));

        // No age range
        $this->assertNull($this->tool->extractMinAgeFromCategoryPath('МЕБЛІ ТА ОРГАНІЗАЦІЯ'));
        $this->assertNull($this->tool->extractMinAgeFromCategoryPath('НАВЧАЛЬНІ ПОСІБНИКИ'));
        $this->assertNull($this->tool->extractMinAgeFromCategoryPath(''));
    }

    public function test_adjacent_upper_age_post_filter_blocks_old_products(): void
    {
        // Simulate: query "подарунок на 1 рік" (12 months)
        // Product from ДОШКІЛЬНЯТАМ 3-7 with NULL age_min_months should be blocked
        // because category min age (36) > requested age (12)
        $catMinMonths = $this->tool->extractMinAgeFromCategoryPath('ІГРАШКИ/ДОШКІЛЬНЯТАМ 3 – 7');
        $requestedAgeMonths = 12;

        $this->assertNotNull($catMinMonths);
        $this->assertTrue($requestedAgeMonths < $catMinMonths,
            'Requested age 12mo should be less than category min 36mo');

        // Product from ТОДЛЕРАМ 1-3 with NULL age should pass
        $catMinMonths2 = $this->tool->extractMinAgeFromCategoryPath('ІГРАШКИ/ТОДЛЕРАМ 1 – 3');
        $this->assertFalse($requestedAgeMonths < $catMinMonths2,
            'Requested age 12mo should NOT be less than category min 12mo');
    }
}
