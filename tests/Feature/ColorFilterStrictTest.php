<?php

namespace Tests\Feature;

use App\Services\Agent\Tools\MeiliProductSearchTool;
use App\Services\Search\ColorService;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Guards prod bug where user asks "знайди мені чорну плитоноску" but bot
 * returns products with color=Мультикам/Піксель (non-black) and hallucinates
 * "Ось чорні плитоноски:" in the intro.
 *
 * Fix stack:
 * 1. ColorService::detectColor recognises inflections "чорну", "чорного", etc.
 * 2. BaseAgent::toolSearchProducts injects detected color into filters when
 *    GPT omits the `color` param.
 * 3. MeiliProductSearchTool::postFilterByColor strictly rejects products
 *    whose explicit color_norm does not match the requested group and no
 *    longer scans search_index (too noisy).
 */
class ColorFilterStrictTest extends TestCase
{
    #[Test]
    public function color_service_detects_black_in_accusative_form(): void
    {
        $service = app(ColorService::class);

        $this->assertSame('black', $service->detectColor('знайди мені чорну плитоноску'));
        $this->assertSame('black', $service->detectColor('хочу чорного кольору'));
        $this->assertSame('black', $service->detectColor('black helmet'));
    }

    #[Test]
    public function post_filter_rejects_product_with_mismatched_color_norm(): void
    {
        /** @var MeiliProductSearchTool $tool */
        $tool = app(MeiliProductSearchTool::class);

        $method = new ReflectionMethod(MeiliProductSearchTool::class, 'postFilterByColor');
        $method->setAccessible(true);

        $hits = [
            ['id' => 1, 'title' => 'Плитоноска Multicam', 'color' => 'Мультикам', 'color_norm' => 'multicam', 'search_index' => 'плитоноска з чорними стропами'],
            ['id' => 2, 'title' => 'Плитоноска "Схід 24"', 'color' => 'Піксель', 'color_norm' => 'pixel', 'search_index' => 'плитоноска'],
            ['id' => 3, 'title' => 'Чорна плитоноска AR500', 'color' => 'Чорний', 'color_norm' => 'black', 'search_index' => 'плитоноска'],
        ];

        $filtered = $method->invoke($tool, $hits, 'чорний');

        $this->assertCount(1, $filtered, 'Only the explicitly black product should pass');
        $this->assertSame(3, $filtered[0]['id']);
    }

    #[Test]
    public function post_filter_ignores_search_index_noise(): void
    {
        /** @var MeiliProductSearchTool $tool */
        $tool = app(MeiliProductSearchTool::class);

        $method = new ReflectionMethod(MeiliProductSearchTool::class, 'postFilterByColor');
        $method->setAccessible(true);

        // Product has no color_norm, title/color don't mention black, but
        // search_index contains "чорн" (describing straps). Must NOT leak through.
        $hits = [
            ['id' => 1, 'title' => 'Плитоноска ATAKA Multicam', 'color' => '', 'color_norm' => null, 'search_index' => 'плитоноска з чорними стропами та фастексом'],
        ];

        $filtered = $method->invoke($tool, $hits, 'чорний');

        $this->assertCount(0, $filtered);
    }

    #[Test]
    public function post_filter_allows_product_with_black_in_title_and_no_color_norm(): void
    {
        /** @var MeiliProductSearchTool $tool */
        $tool = app(MeiliProductSearchTool::class);

        $method = new ReflectionMethod(MeiliProductSearchTool::class, 'postFilterByColor');
        $method->setAccessible(true);

        $hits = [
            ['id' => 1, 'title' => 'Чорна плитоноска', 'color' => '', 'color_norm' => null, 'search_index' => 'плитоноска'],
        ];

        $filtered = $method->invoke($tool, $hits, 'чорний');

        $this->assertCount(1, $filtered);
    }
}
