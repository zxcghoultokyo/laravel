<?php

namespace Tests\Unit;

use App\Enums\Intent;
use PHPUnit\Framework\TestCase;

class IntentEnumTest extends TestCase
{
    /**
     * @dataProvider fromStringProvider
     */
    public function test_from_string_normalizes_intent(string $input, Intent $expected): void
    {
        $this->assertSame($expected, Intent::fromString($input));
    }

    public static function fromStringProvider(): array
    {
        return [
            // Standard cases
            ['product_search', Intent::ProductSearch],
            ['order_status', Intent::OrderStatus],
            ['faq', Intent::Faq],
            ['smalltalk', Intent::SmallTalk],
            ['unknown', Intent::Unknown],
            ['product_comparison', Intent::ProductComparison],
            
            // Case insensitive
            ['PRODUCT_SEARCH', Intent::ProductSearch],
            ['Product_Search', Intent::ProductSearch],
            ['ORDER_STATUS', Intent::OrderStatus],
            ['FAQ', Intent::Faq],
            ['SMALLTALK', Intent::SmallTalk],
            ['PRODUCT_COMPARISON', Intent::ProductComparison],
            
            // Variations
            ['product-search', Intent::ProductSearch],
            ['productsearch', Intent::ProductSearch],
            ['order-status', Intent::OrderStatus],
            ['orderstatus', Intent::OrderStatus],
            ['small_talk', Intent::SmallTalk],
            ['small-talk', Intent::SmallTalk],
            ['small talk', Intent::SmallTalk],
            ['productcomparison', Intent::ProductComparison],
            ['comparison', Intent::ProductComparison],
            ['compare', Intent::ProductComparison],
            
            // Empty and garbage
            ['', Intent::Unknown],
            ['gibberish', Intent::Unknown],
            ['random_intent', Intent::Unknown],
        ];
    }

    public function test_is_product_search(): void
    {
        $this->assertTrue(Intent::ProductSearch->isProductSearch());
        $this->assertFalse(Intent::OrderStatus->isProductSearch());
        $this->assertFalse(Intent::Faq->isProductSearch());
        $this->assertFalse(Intent::ProductComparison->isProductSearch());
    }

    public function test_is_product_comparison(): void
    {
        $this->assertTrue(Intent::ProductComparison->isProductComparison());
        $this->assertFalse(Intent::ProductSearch->isProductComparison());
        $this->assertFalse(Intent::OrderStatus->isProductComparison());
        $this->assertFalse(Intent::Faq->isProductComparison());
    }

    public function test_is_product_related(): void
    {
        $this->assertTrue(Intent::ProductSearch->isProductRelated());
        $this->assertTrue(Intent::ProductComparison->isProductRelated());
        $this->assertFalse(Intent::OrderStatus->isProductRelated());
        $this->assertFalse(Intent::Faq->isProductRelated());
        $this->assertFalse(Intent::SmallTalk->isProductRelated());
    }

    public function test_value_property(): void
    {
        $this->assertSame('product_search', Intent::ProductSearch->value);
        $this->assertSame('order_status', Intent::OrderStatus->value);
        $this->assertSame('faq', Intent::Faq->value);
        $this->assertSame('smalltalk', Intent::SmallTalk->value);
        $this->assertSame('unknown', Intent::Unknown->value);
        $this->assertSame('product_comparison', Intent::ProductComparison->value);
    }
}
