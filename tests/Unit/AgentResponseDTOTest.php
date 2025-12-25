<?php

namespace Tests\Unit;

use App\DTO\AgentResponseDTO;
use App\Enums\Intent;
use PHPUnit\Framework\TestCase;

class AgentResponseDTOTest extends TestCase
{
    public function test_creates_product_search_response(): void
    {
        $products = [
            ['id' => 1, 'title' => 'Product 1', 'price' => 1000],
            ['id' => 2, 'title' => 'Product 2', 'price' => 2000],
        ];

        $dto = AgentResponseDTO::productSearch(
            message: 'Ось варіанти:',
            products: $products,
            refinedQuery: 'плитоноска',
            filters: ['color' => 'black'],
            chosenIds: [1, 2],
            ambiguous: true,
        );

        $this->assertSame('Ось варіанти:', $dto->message);
        $this->assertCount(2, $dto->products);
        $this->assertSame(Intent::ProductSearch, $dto->intent);
        $this->assertTrue($dto->ambiguous);
        $this->assertSame([1, 2], $dto->chosenIds);
        
        // Check toArray output
        $array = $dto->toArray();
        $this->assertSame('product_search', $array['meta']['intent']);
    }

    public function test_creates_order_status_response(): void
    {
        $orders = [
            ['id' => '12345', 'status' => 'shipped'],
        ];

        $dto = AgentResponseDTO::orderStatus(
            message: 'Замовлення №12345',
            orders: $orders,
            criteria: ['order_id' => '12345'],
            found: 1,
        );

        $this->assertSame('Замовлення №12345', $dto->message);
        $this->assertEmpty($dto->products);
        $this->assertSame(Intent::OrderStatus, $dto->intent);
        
        // Check toArray output
        $array = $dto->toArray();
        $this->assertSame('order_status', $array['meta']['intent']);
        $this->assertSame(1, $array['meta']['found']);
        $this->assertSame($orders, $array['meta']['orders']);
    }

    public function test_creates_faq_response(): void
    {
        $dto = AgentResponseDTO::faq(
            message: 'Доставка здійснюється...',
            topic: 'delivery',
        );

        $this->assertSame('Доставка здійснюється...', $dto->message);
        $this->assertEmpty($dto->products);
        $this->assertSame(Intent::Faq, $dto->intent);
        
        // Check toArray output
        $array = $dto->toArray();
        $this->assertSame('faq', $array['meta']['intent']);
        $this->assertSame('delivery', $array['meta']['topic']);
    }

    public function test_creates_smalltalk_response(): void
    {
        $dto = AgentResponseDTO::smallTalk(
            message: 'Привіт! Чим можу допомогти?',
        );

        $this->assertSame('Привіт! Чим можу допомогти?', $dto->message);
        $this->assertEmpty($dto->products);
        $this->assertSame(Intent::SmallTalk, $dto->intent);
        
        // Check toArray output
        $array = $dto->toArray();
        $this->assertSame('smalltalk', $array['meta']['intent']);
    }

    public function test_creates_no_results_response(): void
    {
        $dto = AgentResponseDTO::noResults('невідомий товар');

        $this->assertStringContainsString('невідомий товар', $dto->message);
        $this->assertEmpty($dto->products);
        $this->assertSame(Intent::ProductSearch, $dto->intent);
        $this->assertFalse($dto->ambiguous);
        $this->assertEmpty($dto->chosenIds);
        
        // Check toArray output
        $array = $dto->toArray();
        $this->assertSame('product_search', $array['meta']['intent']);
    }

    public function test_to_array_conversion(): void
    {
        $dto = AgentResponseDTO::smallTalk(message: 'Test');
        
        $array = $dto->toArray();

        $this->assertArrayHasKey('message', $array);
        $this->assertArrayHasKey('products', $array);
        $this->assertArrayHasKey('meta', $array);
        $this->assertSame('Test', $array['message']);
        $this->assertEmpty($array['products']);
        $this->assertSame('smalltalk', $array['meta']['intent']);
    }

    public function test_has_products_method(): void
    {
        $withProducts = AgentResponseDTO::productSearch(
            message: 'Found',
            products: [['id' => 1]],
            refinedQuery: 'test',
            filters: [],
            chosenIds: [1],
        );

        $withoutProducts = AgentResponseDTO::smallTalk(message: 'Hi');

        $this->assertTrue($withProducts->hasProducts());
        $this->assertFalse($withoutProducts->hasProducts());
    }
    
    public function test_with_follow_up_method(): void
    {
        $dto = AgentResponseDTO::productSearch(
            message: 'Ось товари:',
            products: [['id' => 1]],
        );
        
        $withFollowUp = $dto->withFollowUp('Який колір вам потрібен?');
        
        $this->assertStringContainsString('Ось товари:', $withFollowUp->message);
        $this->assertStringContainsString('Який колір вам потрібен?', $withFollowUp->message);
        
        // Original should be unchanged (immutability)
        $this->assertStringNotContainsString('Який колір', $dto->message);
    }
}
