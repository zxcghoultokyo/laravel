<?php

namespace Tests\Feature;

use App\Services\Agent\BaseAgent;
use App\Services\Agent\Tools\MeiliProductSearchTool;
use App\Services\Agent\Tools\ProductDetailsTool;
use App\Services\Horoshop\OrderSearchService;
use Tests\TestCase;

class ForceSearchOnHallucinationTest extends TestCase
{
    private BaseAgent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $searchTool = $this->createMock(MeiliProductSearchTool::class);
        $searchTool->method('search')->willReturn([
            ['id' => 1, 'title' => 'Реальний товар 1'],
            ['id' => 2, 'title' => 'Реальний товар 2'],
            ['id' => 3, 'title' => 'Реальний товар 3'],
        ]);
        $searchTool->method('getCurrentTenantId')->willReturn(20);

        $detailsTool = $this->createMock(ProductDetailsTool::class);
        $detailsTool->method('getCards')->willReturn([]);

        $orderSearch = $this->createMock(OrderSearchService::class);

        $this->agent = new class($searchTool, $detailsTool, $orderSearch) extends BaseAgent
        {
            public function handle(string $message, array $context = []): array
            {
                return [];
            }

            public function testForceSearchOnHallucinatedProducts(string $gptResponse, string $originalMessage): ?array
            {
                return $this->forceSearchOnHallucinatedProducts($gptResponse, $originalMessage);
            }
        };
    }

    public function test_detects_numbered_list_hallucination(): void
    {
        $gptResponse = "Ось деякі чудові варіанти для великодніх подарунків:\n\n1. Дерев'яні яйця для розпису\n2. Набір для випікання пасок\n3. Великодній кошик з іграшками\n\nЗверніть увагу на ці товари!";
        $result = $this->agent->testForceSearchOnHallucinatedProducts($gptResponse, 'порадь щось на великдень');

        $this->assertNotNull($result);
        $this->assertArrayHasKey('products', $result);
        $this->assertNotEmpty($result['products']);
        $this->assertArrayHasKey('intro', $result);
    }

    public function test_detects_bulleted_list_hallucination(): void
    {
        $gptResponse = "Рекомендую звернути увагу на:\n\n- Монтессорі набір для розвитку\n- Дерев'яний конструктор\n- Розвиваючий м'ячик\n\nЦе чудовий вибір!";
        $result = $this->agent->testForceSearchOnHallucinatedProducts($gptResponse, 'іграшки монтессорі');

        $this->assertNotNull($result);
        $this->assertNotEmpty($result['products']);
    }

    public function test_ignores_plain_text_greeting(): void
    {
        $gptResponse = 'Вітаю! Я AI-консультант магазину. Чим можу допомогти?';
        $result = $this->agent->testForceSearchOnHallucinatedProducts($gptResponse, 'привіт');

        $this->assertNull($result);
    }

    public function test_ignores_faq_response(): void
    {
        $gptResponse = "Ми здійснюємо доставку:\n1. Нова Пошта — по всій Україні\n2. Укрпошта — для невеликих замовлень\n3. Самовивіз — з нашого магазину";
        $result = $this->agent->testForceSearchOnHallucinatedProducts($gptResponse, 'як ви доставляєте');

        $this->assertNull($result);
    }

    public function test_ignores_short_lists(): void
    {
        $gptResponse = "Рекомендую:\n1. Щось цікаве\n2. Ще щось";
        $result = $this->agent->testForceSearchOnHallucinatedProducts($gptResponse, 'покажи іграшки');

        $this->assertNull($result);
    }

    public function test_requires_recommendation_phrase(): void
    {
        $gptResponse = "Інформація про товари:\n\n1. Дерев'яні яйця для розпису\n2. Набір для випікання пасок\n3. Великодній кошик з іграшками";
        $result = $this->agent->testForceSearchOnHallucinatedProducts($gptResponse, 'порадь щось');

        $this->assertNull($result);
    }

    public function test_strips_filler_words_from_search_query(): void
    {
        $gptResponse = "Можу запропонувати такі товари:\n\n1. Навчальний зошит Монтессорі\n2. Дерев'яний конструктор\n3. Розвиваючий набір\n\nОберіть що сподобається!";
        $result = $this->agent->testForceSearchOnHallucinatedProducts($gptResponse, 'порадь мені щось на великдень будь ласка');

        $this->assertNotNull($result);
        $this->assertStringContainsString('великдень', $result['intro']);
    }
}
