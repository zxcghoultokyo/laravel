<?php

namespace Tests\Feature;

use App\Services\Agent\Tools\MeiliProductSearchTool;
use Tests\TestCase;

class OutOfSeasonFilterTest extends TestCase
{
    private \ReflectionMethod $method;

    private MeiliProductSearchTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tool = app(MeiliProductSearchTool::class);
        $this->method = new \ReflectionMethod(MeiliProductSearchTool::class, 'filterOutOfSeasonProducts');
        $this->method->setAccessible(true);
    }

    private function filter(array $hits, string $query): array
    {
        return $this->method->invoke($this->tool, $hits, $query);
    }

    public function test_drops_christmas_in_april(): void
    {
        $currentMonth = (int) date('n');
        if (in_array($currentMonth, [11, 12, 1], true)) {
            $this->markTestSkipped('Christmas is in season');
        }

        $hits = [
            ['title' => 'Ялинкова прикраса, що дарує добро'],
            ['title' => 'Різдвяний дитячий набір для випікання'],
            ['title' => 'Монтессорі м\'ячик Такане Міні'],
        ];
        $result = $this->filter($hits, 'іграшка на 5 років');
        $this->assertCount(1, $result);
        $this->assertSame('Монтессорі м\'ячик Такане Міні', $result[0]['title']);
    }

    public function test_keeps_christmas_when_user_asks(): void
    {
        $hits = [
            ['title' => 'Ялинкова прикраса'],
            ['title' => 'Монтессорі м\'ячик Такане Міні'],
        ];
        $result = $this->filter($hits, 'подаруй щось різдвяне');
        $this->assertCount(2, $result);
    }

    public function test_keeps_easter_in_april(): void
    {
        $currentMonth = (int) date('n');
        if (! in_array($currentMonth, [3, 4, 5], true)) {
            $this->markTestSkipped('Easter season check only in Mar-May');
        }

        $hits = [
            ['title' => 'Великодній дитячий Монтессорі зошит pdf'],
            ['title' => 'Звичайна іграшка'],
        ];
        $result = $this->filter($hits, 'іграшка на 3 роки');
        $this->assertCount(2, $result);
    }

    public function test_safety_keeps_all_when_all_flagged(): void
    {
        $currentMonth = (int) date('n');
        if (in_array($currentMonth, [11, 12, 1], true)) {
            $this->markTestSkipped('Christmas in season');
        }

        $hits = [
            ['title' => 'Ялинкова прикраса'],
            ['title' => 'Різдвяний набір'],
        ];
        $result = $this->filter($hits, 'іграшка');
        // All out of season, but filter preserves to avoid empty list
        $this->assertCount(2, $result);
    }

    public function test_noop_for_non_seasonal_items(): void
    {
        $hits = [
            ['title' => "Дерев'яний барабан"],
            ['title' => 'Монтессорі дошка'],
        ];
        $result = $this->filter($hits, 'що завгодно');
        $this->assertCount(2, $result);
    }
}
