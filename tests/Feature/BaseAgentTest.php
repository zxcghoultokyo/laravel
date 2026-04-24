<?php

namespace Tests\Feature;

use App\Services\Agent\BaseAgent;
use App\Services\Agent\Tools\MeiliProductSearchTool;
use App\Services\Agent\Tools\ProductDetailsTool;
use App\Services\Horoshop\OrderSearchService;
use Tests\TestCase;

/**
 * Tests for BaseAgent protected methods via a TestableBaseAgent subclass.
 * These methods form the core text processing & response parsing pipeline.
 */
class BaseAgentTest extends TestCase
{
    private TestableBaseAgent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $searchTool = $this->createMock(MeiliProductSearchTool::class);
        $detailsTool = $this->createMock(ProductDetailsTool::class);
        $orderSearch = $this->createMock(OrderSearchService::class);

        $this->agent = new TestableBaseAgent($searchTool, $detailsTool, $orderSearch);
    }

    // ───────────────────────────────────────────────
    // stripUrlsFromText
    // ───────────────────────────────────────────────

    public function test_strip_urls_removes_known_anchor_markdown_links(): void
    {
        $text = 'Ось товар [Детальніше](https://shop.com/product/123) тут';
        $result = $this->agent->exposeStripUrlsFromText($text);

        $this->assertStringNotContainsString('https://', $result);
        $this->assertStringNotContainsString('[Детальніше]', $result);
        $this->assertStringContainsString('тут', $result);
    }

    public function test_strip_urls_keeps_text_from_descriptive_markdown_links(): void
    {
        $text = 'Подивіться [Шолом Ops-Core](https://shop.com/helmet) для захисту';
        $result = $this->agent->exposeStripUrlsFromText($text);

        $this->assertStringContainsString('Шолом Ops-Core', $result);
        $this->assertStringNotContainsString('https://', $result);
    }

    public function test_strip_urls_removes_bare_urls(): void
    {
        $text = 'Ось товар на https://shop.com/product/123 дуже хороший';
        $result = $this->agent->exposeStripUrlsFromText($text);

        $this->assertStringNotContainsString('https://', $result);
        $this->assertStringContainsString('хороший', $result);
    }

    public function test_strip_urls_removes_bracket_text_without_url(): void
    {
        $text = 'Товар [Переглянути] тут';
        $result = $this->agent->exposeStripUrlsFromText($text);

        $this->assertStringNotContainsString('[Переглянути]', $result);
    }

    public function test_strip_urls_removes_oss_what_i_found_phrases(): void
    {
        $text = "Ось що я знайшов:\nШоломи та аксесуари";
        $result = $this->agent->exposeStripUrlsFromText($text);

        $this->assertStringNotContainsString('Ось що я знайшов', $result);
        $this->assertStringContainsString('Шоломи та аксесуари', $result);
    }

    public function test_strip_urls_preserves_normal_text(): void
    {
        $text = 'Це чудовий шолом для тактичних операцій';
        $result = $this->agent->exposeStripUrlsFromText($text);

        $this->assertSame($text, $result);
    }

    // ───────────────────────────────────────────────
    // stripLinksForGpt
    // ───────────────────────────────────────────────

    public function test_strip_links_removes_link_and_slug_from_products(): void
    {
        $result = $this->agent->exposeStripLinksForGpt([
            'products' => [
                ['id' => 1, 'title' => 'Product', 'price' => 500, 'link' => 'https://shop.com/p/1', 'slug' => 'product-1'],
            ],
        ]);

        $this->assertArrayNotHasKey('link', $result['products'][0]);
        $this->assertArrayNotHasKey('slug', $result['products'][0]);
        $this->assertSame('Product', $result['products'][0]['title']);
        $this->assertSame(500, $result['products'][0]['price']);
    }

    public function test_strip_links_removes_link_from_size_variants(): void
    {
        $result = $this->agent->exposeStripLinksForGpt([
            'products' => [
                [
                    'id' => 1,
                    'title' => 'Jacket',
                    'size_variants' => [
                        ['size' => 'L', 'link' => 'https://shop.com/p/1?size=L'],
                        ['size' => 'M', 'link' => 'https://shop.com/p/1?size=M'],
                    ],
                ],
            ],
        ]);

        foreach ($result['products'][0]['size_variants'] as $variant) {
            $this->assertArrayNotHasKey('link', $variant);
            $this->assertArrayHasKey('size', $variant);
        }
    }

    public function test_strip_links_handles_single_product(): void
    {
        $result = $this->agent->exposeStripLinksForGpt([
            'product' => [
                'id' => 1, 'title' => 'Helmet', 'link' => 'https://shop.com/h/1', 'slug' => 'helmet-1',
            ],
        ]);

        $this->assertArrayNotHasKey('link', $result['product']);
        $this->assertArrayNotHasKey('slug', $result['product']);
        $this->assertSame('Helmet', $result['product']['title']);
    }

    public function test_strip_links_does_not_modify_data_without_links(): void
    {
        $input = [
            'products' => [
                ['id' => 1, 'title' => 'Product', 'price' => 100],
            ],
        ];
        $result = $this->agent->exposeStripLinksForGpt($input);

        $this->assertSame($input, $result);
    }

    // ───────────────────────────────────────────────
    // parseStructuredResponse
    // ───────────────────────────────────────────────

    public function test_parse_structured_response_with_valid_json_and_products(): void
    {
        $responseText = '{"intro": "Ось товари:", "products": [{"article": "ABC-1", "comment": "Добрий варіант"}], "outro": "Замовляй!"}';
        $allProducts = [
            ['id' => 1, 'article' => 'ABC-1', 'title' => 'Product ABC'],
        ];

        $result = $this->agent->exposeParseStructuredResponse($responseText, $allProducts);

        $this->assertSame('Ось товари:', $result['intro']);
        $this->assertSame('Замовляй!', $result['outro']);
        $this->assertCount(1, $result['products']);
        $this->assertSame('ABC-1', $result['products'][0]['article']);
    }

    public function test_parse_structured_response_with_partial_article_match(): void
    {
        $responseText = '{"intro": "Ось:", "products": [{"article": "ABC", "comment": ""}]}';
        $allProducts = [
            ['id' => 1, 'article' => 'ABC-123', 'title' => 'Product ABC Full'],
        ];

        $result = $this->agent->exposeParseStructuredResponse($responseText, $allProducts);

        $this->assertCount(1, $result['products']);
        $this->assertSame('ABC-123', $result['products'][0]['article']);
    }

    public function test_parse_structured_response_plain_text_fallback(): void
    {
        $responseText = 'Я знайшов кілька товарів для вас.';
        $allProducts = [
            ['id' => 1, 'article' => 'A1', 'title' => 'P1'],
            ['id' => 2, 'article' => 'A2', 'title' => 'P2'],
        ];

        $result = $this->agent->exposeParseStructuredResponse($responseText, $allProducts);

        $this->assertSame($responseText, $result['intro']);
        $this->assertCount(2, $result['products']);
    }

    public function test_parse_structured_response_with_text_key(): void
    {
        $responseText = '{"text": "На жаль, нічого не знайшла"}';
        $allProducts = [];

        $result = $this->agent->exposeParseStructuredResponse($responseText, $allProducts);

        $this->assertSame('На жаль, нічого не знайшла', $result['intro']);
    }

    public function test_parse_structured_response_empty_products_fallback_to_all(): void
    {
        $responseText = '{"intro": "Ось:", "products": [{"article": "NONEXISTENT", "comment": "test"}]}';
        $allProducts = [
            ['id' => 1, 'article' => 'REAL-1', 'title' => 'Real Product'],
        ];

        $result = $this->agent->exposeParseStructuredResponse($responseText, $allProducts);

        // When no articles match, should fall back to first 5 from allProducts
        $this->assertNotEmpty($result['products']);
    }

    public function test_parse_structured_response_limits_to_5_products(): void
    {
        $responseText = 'Тут є товари.';
        $allProducts = array_map(fn ($i) => ['id' => $i, 'article' => "A{$i}", 'title' => "P{$i}"], range(1, 10));

        $result = $this->agent->exposeParseStructuredResponse($responseText, $allProducts);

        $this->assertCount(5, $result['products']);
    }

    // ───────────────────────────────────────────────
    // isFreshQuery
    // ───────────────────────────────────────────────

    public function test_is_fresh_query_confirmations_are_not_fresh(): void
    {
        $confirmations = ['так', 'ні', 'добре', 'ок', 'ще', 'дозволяю', 'давай', 'інші', 'більше'];

        foreach ($confirmations as $word) {
            $this->assertFalse(
                $this->agent->exposeIsFreshQuery($word, [['role' => 'user', 'content' => 'шоломи']]),
                "'{$word}' should NOT be a fresh query"
            );
        }
    }

    public function test_is_fresh_query_category_names_are_fresh(): void
    {
        $categories = ['плитоноски', 'шоломи', 'берці', 'рюкзаки', 'куртки', 'штани'];

        foreach ($categories as $cat) {
            $this->assertTrue(
                $this->agent->exposeIsFreshQuery($cat, []),
                "'{$cat}' should be a fresh query"
            );
        }
    }

    public function test_is_fresh_query_search_verbs_are_fresh(): void
    {
        $queries = ['покажи куртки', 'знайди берці', 'шукаю рюкзак'];

        foreach ($queries as $q) {
            $this->assertTrue(
                $this->agent->exposeIsFreshQuery($q, [['role' => 'user', 'content' => 'шоломи']]),
                "'{$q}' should be a fresh query"
            );
        }
    }

    public function test_is_fresh_query_empty_history_is_always_fresh(): void
    {
        $this->assertTrue(
            $this->agent->exposeIsFreshQuery('будь-що', []),
            'Any message with empty history should be fresh'
        );
    }

    public function test_is_fresh_query_brand_names_are_fresh(): void
    {
        $brands = ['defcon', 'm-tac', 'helikon', '5.11'];

        foreach ($brands as $brand) {
            $this->assertTrue(
                $this->agent->exposeIsFreshQuery($brand, []),
                "'{$brand}' should be a fresh query"
            );
        }
    }

    // ───────────────────────────────────────────────
    // dedupeProducts
    // ───────────────────────────────────────────────

    public function test_dedupe_removes_duplicates_by_id(): void
    {
        $products = [
            ['id' => 1, 'title' => 'Product A'],
            ['id' => 2, 'title' => 'Product B'],
            ['id' => 1, 'title' => 'Product A Duplicate'],
        ];

        $result = $this->agent->exposeDedupeProducts($products);

        $this->assertCount(2, $result);
        $this->assertSame('Product A', $result[0]['title']);
        $this->assertSame('Product B', $result[1]['title']);
    }

    public function test_dedupe_skips_products_without_id(): void
    {
        $products = [
            ['title' => 'No ID Product'],
            ['id' => 1, 'title' => 'With ID'],
        ];

        $result = $this->agent->exposeDedupeProducts($products);

        $this->assertCount(1, $result);
        $this->assertSame('With ID', $result[0]['title']);
    }

    public function test_dedupe_preserves_order(): void
    {
        $products = [
            ['id' => 3, 'title' => 'Third'],
            ['id' => 1, 'title' => 'First'],
            ['id' => 2, 'title' => 'Second'],
        ];

        $result = $this->agent->exposeDedupeProducts($products);

        $this->assertSame('Third', $result[0]['title']);
        $this->assertSame('First', $result[1]['title']);
        $this->assertSame('Second', $result[2]['title']);
    }

    // ───────────────────────────────────────────────
    // fallbackResponse
    // ───────────────────────────────────────────────

    public function test_fallback_response_with_no_search_results(): void
    {
        $searchTool = $this->createMock(MeiliProductSearchTool::class);
        $searchTool->method('search')->willReturn([]);
        $searchTool->method('getCurrentTenantId')->willReturn(2);

        $detailsTool = $this->createMock(ProductDetailsTool::class);
        $orderSearch = $this->createMock(OrderSearchService::class);

        $agent = new TestableBaseAgent($searchTool, $detailsTool, $orderSearch);
        $result = $agent->exposeFallbackResponse('невідомий запит');

        $this->assertSame('fallback', $result['meta']['agent']);
        $this->assertSame('error', $result['meta']['intent']);
        $this->assertEmpty($result['products']);
        $this->assertStringContainsString('технічні труднощі', $result['message']);
    }

    public function test_fallback_response_with_search_results(): void
    {
        $searchTool = $this->createMock(MeiliProductSearchTool::class);
        $searchTool->method('search')->willReturn([
            ['id' => 1, 'title' => 'Found Product'],
        ]);
        $searchTool->method('getCurrentTenantId')->willReturn(2);

        $detailsTool = $this->createMock(ProductDetailsTool::class);
        $detailsTool->method('getCards')->willReturn([
            ['id' => 1, 'title' => 'Found Product', 'price' => 100],
        ]);

        $orderSearch = $this->createMock(OrderSearchService::class);

        $agent = new TestableBaseAgent($searchTool, $detailsTool, $orderSearch);
        $result = $agent->exposeFallbackResponse('шоломи');

        $this->assertSame('fallback', $result['meta']['agent']);
        $this->assertSame('product_search', $result['meta']['intent']);
        $this->assertNotEmpty($result['products']);
    }

    // ───────────────────────────────────────────────
    // getTools
    // ───────────────────────────────────────────────

    public function test_get_tools_returns_all_required_functions(): void
    {
        $tools = $this->agent->exposeGetTools();

        $functionNames = array_map(fn ($t) => $t['function']['name'], $tools);

        $this->assertContains('search_products', $functionNames);
        $this->assertContains('get_popular_products', $functionNames);
        $this->assertContains('get_product_details', $functionNames);
        $this->assertContains('get_order_status', $functionNames);
        $this->assertContains('get_categories', $functionNames);
        $this->assertContains('get_brands', $functionNames);
    }

    public function test_get_tools_search_products_has_query_parameter(): void
    {
        $tools = $this->agent->exposeGetTools();

        $searchTool = collect($tools)->first(fn ($t) => $t['function']['name'] === 'search_products');

        $this->assertNotNull($searchTool);
        $this->assertSame('function', $searchTool['type']);

        $params = $searchTool['function']['parameters'];
        $this->assertArrayHasKey('query', $params['properties']);
        $this->assertContains('query', $params['required']);
    }

    public function test_get_tools_search_products_has_category_parameter(): void
    {
        $tools = $this->agent->exposeGetTools();

        $searchTool = collect($tools)->first(fn ($t) => $t['function']['name'] === 'search_products');

        $this->assertNotNull($searchTool);

        $params = $searchTool['function']['parameters'];
        $this->assertArrayHasKey('category', $params['properties']);
        $this->assertSame('string', $params['properties']['category']['type']);
    }
}

/**
 * Concrete subclass exposing BaseAgent protected methods for testing.
 */
class TestableBaseAgent extends BaseAgent
{
    public function exposeStripUrlsFromText(string $text): string
    {
        return $this->stripUrlsFromText($text);
    }

    public function exposeStripLinksForGpt(array $result): array
    {
        return $this->stripLinksForGpt($result);
    }

    public function exposeParseStructuredResponse(string $responseText, array $allProducts): array
    {
        return $this->parseStructuredResponse($responseText, $allProducts);
    }

    public function exposeIsFreshQuery(string $message, array $history): bool
    {
        return $this->isFreshQuery($message, $history);
    }

    public function exposeDedupeProducts(array $products): array
    {
        return $this->dedupeProducts($products);
    }

    public function exposeFallbackResponse(string $message): array
    {
        return $this->fallbackResponse($message);
    }

    public function exposeGetTools(): array
    {
        return $this->getTools();
    }

    public function exposeFilterTenantBabyQueryProducts(array $products, string $message, ?int $tenantId, ?string $sessionId = null): array
    {
        return $this->filterTenantBabyQueryProducts($products, $message, $tenantId, $sessionId);
    }
}
