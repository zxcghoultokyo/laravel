<?php

namespace Tests\Feature;

use App\Services\Agent\BaseAgent;
use App\Services\Agent\FunctionCallingAgent;
use Tests\TestCase;

class ReasoningAndFilterTest extends TestCase
{
    private BaseAgent $agent;

    private \ReflectionMethod $reasoning;

    private \ReflectionMethod $negative;

    private \ReflectionMethod $babyFilter;

    private \ReflectionMethod $dedup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = app(FunctionCallingAgent::class);

        $this->reasoning = new \ReflectionMethod(BaseAgent::class, 'isReasoningQuery');
        $this->reasoning->setAccessible(true);

        $this->negative = new \ReflectionMethod(BaseAgent::class, 'isNegativeFeedbackQuery');
        $this->negative->setAccessible(true);

        $this->babyFilter = new \ReflectionMethod(BaseAgent::class, 'filterTenantBabyQueryProducts');
        $this->babyFilter->setAccessible(true);

        $this->dedup = new \ReflectionMethod(BaseAgent::class, 'dedupByParentArticle');
        $this->dedup->setAccessible(true);
    }

    // ---------- isReasoningQuery ----------

    public static function reasoningQueries(): array
    {
        return [
            ['порівняй Peltor і Earmor'],
            ['порадь 3 варіанти для 4 років і коротко порівняй їх'],
            ['який краще для 2 років'],
            ['що краще - дерев\'яний чи пластиковий'],
            ['поясни чому саме ця іграшка підходить'],
            ['плюси і мінуси цього набору'],
            ['в чому різниця між цими моделями'],
            ['підбери за критеріями: до 500 грн, для хлопчика'],
        ];
    }

    /**
     * @dataProvider reasoningQueries
     */
    public function test_reasoning_query_detected(string $q): void
    {
        $this->assertTrue($this->reasoning->invoke($this->agent, $q), "Expected reasoning: {$q}");
    }

    public static function nonReasoningQueries(): array
    {
        return [
            ['шоломи'],
            ['покажи іграшки'],
            ['іграшка на 4 роки'],
            ['щось для малюка від 6 місяців'],
            ['які умови доставки'],
        ];
    }

    /**
     * @dataProvider nonReasoningQueries
     */
    public function test_non_reasoning_query_not_detected(string $q): void
    {
        $this->assertFalse($this->reasoning->invoke($this->agent, $q), "Unexpected reasoning: {$q}");
    }

    // ---------- isNegativeFeedbackQuery ----------

    public static function negativeQueries(): array
    {
        return [
            ['барабан не підходить'],
            ['не треба сертифікатів'],
            ['не потрібна ця іграшка'],
            ['не хочу PDF'],
            ['не той варіант'],
            ['жоден не підходить'],
            ['інший варіант порадь'],
        ];
    }

    /**
     * @dataProvider negativeQueries
     */
    public function test_negative_feedback_detected(string $q): void
    {
        $this->assertTrue($this->negative->invoke($this->agent, $q), "Expected negative: {$q}");
    }

    public function test_positive_query_not_negative(): void
    {
        $this->assertFalse($this->negative->invoke($this->agent, 'покажи іграшки для малюка'));
        $this->assertFalse($this->negative->invoke($this->agent, 'шоломи'));
    }

    // ---------- filterTenantBabyQueryProducts ----------

    public function test_baby_filter_excludes_pdf_category(): void
    {
        $products = [
            ['title' => 'Навчальний зошит "Цикл Життя Курки" pdf', 'category_path' => 'НАВЧАЛЬНІ ПОСІБНИКИ'],
            ['title' => "Дерев'яний барабан", 'category_path' => 'ІГРАШКИ/МАЛЮКАМ 0 – 1'],
        ];
        $result = $this->babyFilter->invoke($this->agent, $products, 'щось для малюка на 3 місяці', 20);
        $this->assertCount(1, $result);
        $this->assertSame("Дерев'яний барабан", $result[0]['title']);
    }

    public function test_baby_filter_keeps_pdf_when_explicitly_asked(): void
    {
        $products = [
            ['title' => 'Навчальний зошит pdf', 'category_path' => 'НАВЧАЛЬНІ ПОСІБНИКИ'],
        ];
        $result = $this->babyFilter->invoke($this->agent, $products, 'покажи зошит pdf', 20);
        $this->assertCount(1, $result);
    }

    public function test_baby_filter_excludes_certificate_without_gift(): void
    {
        $products = [
            ['title' => 'Подарунковий сертифікат bavka', 'category_path' => 'ІГРАШКИ/МАЛЮКАМ 0 – 1'],
            ['title' => 'Набір кубиків', 'category_path' => 'ІГРАШКИ/МАЛЮКАМ 0 – 1'],
        ];
        $result = $this->babyFilter->invoke($this->agent, $products, 'щось для малюка на 6 місяців', 20);
        $this->assertCount(1, $result);
        $this->assertSame('Набір кубиків', $result[0]['title']);
    }

    public function test_baby_filter_keeps_certificate_on_gift_intent(): void
    {
        $products = [
            ['title' => 'Подарунковий сертифікат bavka', 'category_path' => 'ІГРАШКИ/МАЛЮКАМ 0 – 1'],
        ];
        $result = $this->babyFilter->invoke($this->agent, $products, 'хочу подарунок на 1 рік', 20);
        $this->assertCount(1, $result);
    }

    public function test_baby_filter_excludes_care_kit_without_intent(): void
    {
        $products = [
            ['title' => "Набір по догляду за дерев'яними іграшками", 'category_path' => 'ІГРАШКИ/МАЛЮКАМ 0 – 1'],
            ['title' => 'Пелюстковий барабан', 'category_path' => 'ІГРАШКИ/МАЛЮКАМ 0 – 1'],
        ];
        $result = $this->babyFilter->invoke($this->agent, $products, 'щось малюку на 4 місяці', 20);
        $this->assertCount(1, $result);
        $this->assertSame('Пелюстковий барабан', $result[0]['title']);
    }

    public function test_baby_filter_noop_for_other_tenants(): void
    {
        $products = [
            ['title' => 'Навчальний зошит pdf', 'category_path' => 'НАВЧАЛЬНІ ПОСІБНИКИ'],
            ['title' => 'Подарунковий сертифікат', 'category_path' => 'whatever'],
        ];
        $result = $this->babyFilter->invoke($this->agent, $products, 'щось малюку', 2);
        $this->assertCount(2, $result);
    }

    // ---------- dedupByParentArticle ----------

    public function test_dedup_keeps_first_per_parent(): void
    {
        $products = [
            ['article' => 'tak-red', 'parent_article' => 'tak', 'title' => 'Такане red'],
            ['article' => 'tak-blue', 'parent_article' => 'tak', 'title' => 'Такане blue'],
            ['article' => 'ball-1', 'parent_article' => 'ball', 'title' => "М'яч"],
            ['article' => 'tak-green', 'parent_article' => 'tak', 'title' => 'Такане green'],
        ];
        $result = $this->dedup->invoke($this->agent, $products);
        $this->assertCount(2, $result);
        $this->assertSame('Такане red', $result[0]['title']);
        $this->assertSame("М'яч", $result[1]['title']);
    }

    public function test_dedup_falls_back_to_article_when_no_parent(): void
    {
        $products = [
            ['article' => 'a1', 'title' => 'One'],
            ['article' => 'a1', 'title' => 'Duplicate'],
            ['article' => 'a2', 'title' => 'Two'],
        ];
        $result = $this->dedup->invoke($this->agent, $products);
        $this->assertCount(2, $result);
    }
}
