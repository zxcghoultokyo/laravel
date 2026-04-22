<?php

namespace Tests\Feature;

use App\Support\ProductRawExtractor;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards two production bugs:
 *   1. "плитноска" typo was matching "носк" (socks) substring in
 *      BaseAgent::generateContextualIntro and producing "Ось шкарпетки:".
 *   2. HTML entities (&nbsp;, &mdash;, &trade;) from Horoshop descriptions
 *      leaked into the widget unchanged.
 */
class ProductDescriptionCleanupTest extends TestCase
{
    #[Test]
    public function description_strips_tags_and_decodes_html_entities(): void
    {
        $raw = [
            'description' => [
                'ua' => '<p>Захисний бронежилет IOTV Gen 3 Multicam (новий) &mdash; модернізована система&nbsp;з&trade; Quad-Release&amp; швидким скиданням.</p>',
            ],
        ];

        $result = ProductRawExtractor::description($raw, 'ua');

        $this->assertStringNotContainsString('&nbsp;', $result);
        $this->assertStringNotContainsString('&mdash;', $result);
        $this->assertStringNotContainsString('&trade;', $result);
        $this->assertStringNotContainsString('&amp;', $result);
        $this->assertStringNotContainsString('<p>', $result);
        // Entities must have been decoded to their real characters.
        $this->assertStringContainsString('—', $result);
        $this->assertStringContainsString('™', $result);
        $this->assertStringContainsString('&', $result);
    }

    #[Test]
    public function description_handles_empty_input(): void
    {
        $this->assertSame('', ProductRawExtractor::description([], 'ua'));
    }

    #[Test]
    public function description_collapses_whitespace_introduced_by_nbsp_decoding(): void
    {
        $raw = ['description' => ['ua' => 'Текст&nbsp;&nbsp;&nbsp;з&nbsp;пробілами']];

        $result = ProductRawExtractor::description($raw, 'ua');

        // &nbsp; becomes a non-breaking space then collapses via \s+ normalizer.
        $this->assertStringNotContainsString('&nbsp;', $result);
        $this->assertMatchesRegularExpression('/^Текст\s+з\s+пробілами$/u', $result);
    }
}
