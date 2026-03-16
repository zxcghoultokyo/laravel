<?php

namespace Tests\Feature;

use App\Services\Agent\Tools\MeiliProductSearchTool;
use Tests\TestCase;

class TitleRelevanceFilterTest extends TestCase
{
    private MeiliProductSearchTool $tool;

    private \ReflectionMethod $method;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tool = app(MeiliProductSearchTool::class);
        $this->method = new \ReflectionMethod(MeiliProductSearchTool::class, 'filterByTitleRelevance');
        $this->method->setAccessible(true);
    }

    private function makeHit(string $title, string $categoryPath = ''): array
    {
        return [
            'id' => rand(1, 99999),
            'title' => $title,
            'category_path' => $categoryPath,
        ];
    }

    // === T2 (Contractor / tactical) ===

    public function test_sleeping_bag_filters_out_straps_and_warmers(): void
    {
        $hits = [
            $this->makeHit('Спальна система Modular Sleep System (MSS) US Army', 'Каремати і спальники'),
            $this->makeHit('Стропи-фурнітура для спорядження ММ14 піксель', 'Аксесуари'),
            $this->makeHit('Сухі хімічні грілки Hillary (2 шт)', 'Грілки'),
        ];

        $filtered = $this->method->invoke($this->tool, $hits, 'спальник');

        // "спал" stem matches "Спальна" in title — keeps it
        // "спал" stem NOT in "Стропи" or "грілки" — removes them
        $this->assertCount(1, $filtered);
        $this->assertStringContainsString('Спальна система', $filtered[0]['title']);
    }

    public function test_jacket_filters_out_carabiner(): void
    {
        $hits = [
            $this->makeHit('Тактична куртка soft shell black', 'Куртки'),
            $this->makeHit('Тактична куртка soft shell coyote', 'Куртки'),
            $this->makeHit('Карабін ретрактор тактичний з тросом', 'Карабіни'),
        ];

        $filtered = $this->method->invoke($this->tool, $hits, 'куртки');

        // "курт" stem matches "куртка" ✓, not "карабін" ✗
        $this->assertCount(2, $filtered);
        foreach ($filtered as $hit) {
            $this->assertStringContainsString('куртка', mb_strtolower($hit['title']));
        }
    }

    public function test_boots_filters_irrelevant(): void
    {
        $hits = [
            $this->makeHit('Берці тактичні Belleville 590', 'Взуття'),
            $this->makeHit('Шнурки для черевиків', 'Аксесуари'),
        ];

        $filtered = $this->method->invoke($this->tool, $hits, 'берці');

        // "берц" stem matches "Берці" ✓, not "Шнурки" ✗
        $this->assertCount(1, $filtered);
        $this->assertStringContainsString('Берці', $filtered[0]['title']);
    }

    public function test_hoodie_filters_irrelevant(): void
    {
        $hits = [
            $this->makeHit('Худі Hoffmann Black', 'Кофти, худі'),
            $this->makeHit('Худі UAsnipers Dark Brown', 'Кофти, худі'),
            $this->makeHit('Рукавички тактичні', 'Рукавички'),
        ];

        $filtered = $this->method->invoke($this->tool, $hits, 'худі');

        // "худі" (4 chars) matches title "Худі" ✓, not "Рукавички" ✗
        $this->assertCount(2, $filtered);
    }

    public function test_pants_filters_irrelevant(): void
    {
        $hits = [
            $this->makeHit('Штани тактичні M-TAC олива', 'Штани'),
            $this->makeHit('Ремінь тактичний', 'Аксесуари'),
        ];

        $filtered = $this->method->invoke($this->tool, $hits, 'штани');

        // "штан" stem matches "Штани" ✓
        $this->assertCount(1, $filtered);
    }

    // === T20 (BavkaToys / kids) ===

    public function test_bavkatoys_mashinka_filters_irrelevant(): void
    {
        $hits = [
            $this->makeHit("Дерев'яна машинка «Сім'я»", 'ІГРАШКИ/ТОДЛЕРАМ 1 – 3'),
            $this->makeHit('Набір серветок Монтессорі', 'ІГРАШКИ/ДОШКІЛЬНЯТАМ 3 – 7'),
        ];

        $filtered = $this->method->invoke($this->tool, $hits, 'машинка');

        // "маши" stem matches "машинка" ✓, not "серветок" ✗
        $this->assertCount(1, $filtered);
        $this->assertStringContainsString('машинка', mb_strtolower($filtered[0]['title']));
    }

    public function test_bavkatoys_puzzle_filters_irrelevant(): void
    {
        $hits = [
            $this->makeHit('Пазл дерев\'яний Ліс', 'ІГРАШКИ/ДОШКІЛЬНЯТАМ 3 – 7'),
            $this->makeHit('Дошка для письма Літери', 'ІГРАШКИ/ДОШКІЛЬНЯТАМ 3 – 7'),
        ];

        $filtered = $this->method->invoke($this->tool, $hits, 'пазл');

        // "пазл" (4 chars) matches "Пазл" ✓, not "Дошка" ✗
        $this->assertCount(1, $filtered);
    }

    // === Generic / universal ===

    public function test_product_kept_if_stem_in_category(): void
    {
        $hits = [
            $this->makeHit('Австрійський Carinthia Defence 6', 'Каремати і спальники'),
            $this->makeHit('Стропи-фурнітура', 'Аксесуари'),
        ];

        $filtered = $this->method->invoke($this->tool, $hits, 'спальники');

        // "спал" stem matches "спальники" in CATEGORY ✓
        $this->assertCount(1, $filtered);
    }

    public function test_no_filter_for_unknown_query(): void
    {
        $hits = [
            $this->makeHit('Random product A'),
            $this->makeHit('Random product B'),
        ];

        $filtered = $this->method->invoke($this->tool, $hits, 'щось незнайоме');

        // None of these stems match, but all removed → safety net returns originals
        $this->assertCount(2, $filtered);
    }

    public function test_no_filter_for_short_words(): void
    {
        $hits = [
            $this->makeHit('Product A'),
            $this->makeHit('Product B'),
        ];

        // "так" is 3 chars, no stems extracted → skip filtering
        $filtered = $this->method->invoke($this->tool, $hits, 'так');

        $this->assertCount(2, $filtered);
    }

    public function test_no_filter_for_multiword_queries(): void
    {
        $hits = [
            $this->makeHit('Product A'),
            $this->makeHit('Product B'),
        ];

        // 3+ words → skip filtering
        $filtered = $this->method->invoke($this->tool, $hits, 'покажи мені куртки');

        $this->assertCount(2, $filtered);
    }

    public function test_if_filter_removes_all_return_originals(): void
    {
        $hits = [
            $this->makeHit('Completely unrelated item A'),
            $this->makeHit('Completely unrelated item B'),
        ];

        $filtered = $this->method->invoke($this->tool, $hits, 'спальник');

        // Safety net: if everything removed, return originals
        $this->assertCount(2, $filtered);
    }

    public function test_two_word_query_filters_by_both_stems(): void
    {
        $hits = [
            $this->makeHit('Куртка тактична softshell', 'Куртки'),
            $this->makeHit('Тактичний рюкзак 30L', 'Рюкзаки'),
        ];

        // "чорна куртка" → stems: "чорн", "курт"
        // "куртка" has "курт" → kept ✓
        // "рюкзак" has neither "чорн" nor "курт" → removed ✗
        $filtered = $this->method->invoke($this->tool, $hits, 'чорна куртка');

        $this->assertCount(1, $filtered);
        $this->assertStringContainsString('Куртка', $filtered[0]['title']);
    }
}
