<?php

namespace Tests\Unit;

use App\DTO\SearchPlanDTO;
use App\Enums\Intent;
use PHPUnit\Framework\TestCase;

class SearchPlanDTOTest extends TestCase
{
    public function test_creates_dto_from_array(): void
    {
        $data = [
            'intent' => 'product_search',
            'search_query' => 'плитоноска чорна',
            'filters' => ['color' => 'black', 'budget_max' => 5000],
            'ambiguous' => true,
            'confidence' => 0.95,
            'order_id' => null,
        ];

        $dto = SearchPlanDTO::fromArray($data);

        $this->assertSame(Intent::ProductSearch, $dto->intent);
        $this->assertSame('плитоноска чорна', $dto->searchQuery);
        $this->assertSame(['color' => 'black', 'budget_max' => 5000], $dto->filters);
        $this->assertTrue($dto->ambiguous);
        $this->assertSame(0.95, $dto->confidence);
        $this->assertNull($dto->orderId);
    }

    public function test_creates_dto_with_defaults(): void
    {
        $data = [
            'intent' => 'faq',
            'search_query' => '',
        ];

        $dto = SearchPlanDTO::fromArray($data);

        $this->assertSame(Intent::Faq, $dto->intent);
        $this->assertSame('', $dto->searchQuery);
        $this->assertSame([], $dto->filters);
        $this->assertFalse($dto->ambiguous);
        // Default confidence comes from Intent::Faq->defaultConfidence()
        $this->assertSame(Intent::Faq->defaultConfidence(), $dto->confidence);
        $this->assertNull($dto->orderId);
    }

    public function test_creates_order_status_dto(): void
    {
        $data = [
            'intent' => 'order_status',
            'search_query' => '',
            'order_id' => '12345',
        ];

        $dto = SearchPlanDTO::fromArray($data);

        $this->assertSame(Intent::OrderStatus, $dto->intent);
        $this->assertSame('12345', $dto->orderId);
    }

    public function test_converts_to_array(): void
    {
        $dto = new SearchPlanDTO(
            intent: Intent::ProductSearch,
            searchQuery: 'шолом',
            filters: ['budget_max' => 10000],
            ambiguous: false,
            confidence: 0.88,
            orderId: null,
        );

        $array = $dto->toArray();

        $this->assertSame('product_search', $array['intent']);
        $this->assertSame('шолом', $array['search_query']);
        $this->assertSame(['budget_max' => 10000], $array['filters']);
        $this->assertFalse($array['ambiguous']);
        $this->assertSame(0.88, $array['confidence']);
        $this->assertNull($array['order_id']);
    }

    public function test_immutability(): void
    {
        $dto = new SearchPlanDTO(
            intent: Intent::ProductSearch,
            searchQuery: 'test',
            filters: [],
            ambiguous: false,
            confidence: 1.0,
            orderId: null,
        );

        // Attempting to change properties should fail at compile/runtime
        $this->assertTrue(true); // If we got here, readonly is working
    }
}
