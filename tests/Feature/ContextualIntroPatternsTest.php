<?php

namespace Tests\Feature;

use App\Services\Agent\BaseAgent;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Guards the substring-collision bug where "плитноска" (typo for "плитоноска")
 * matched "носк" pattern in $categoryPatterns and produced "Ось шкарпетки:"
 * for a plate carrier search. Fix: add "плитноск" / "плейткер" / "шкарпетк"
 * narrow patterns BEFORE the broad "носк" match.
 */
class ContextualIntroPatternsTest extends TestCase
{
    private function callIntro(string $message): string
    {
        // Anonymous subclass — BaseAgent is abstract but generateContextualIntro
        // has no dependencies on abstract members, so an empty stub suffices.
        $agent = new class extends BaseAgent
        {
            public function __construct() {}

            public function handle(string $message, string $sessionId, array $context = []): array
            {
                return [];
            }
        };

        $method = new ReflectionMethod(BaseAgent::class, 'generateContextualIntro');
        $method->setAccessible(true);

        return $method->invoke($agent, $message, [], false);
    }

    #[Test]
    public function plytnoska_typo_does_not_trigger_socks_intro(): void
    {
        // BUG: "плитноска" contains substring "носк" and was matching socks.
        $intro = $this->callIntro('плитноска');
        $this->assertStringNotContainsString('шкарпетки', mb_strtolower($intro));
        $this->assertStringContainsString('плитоноск', mb_strtolower($intro));
    }

    #[Test]
    public function plytonoska_correct_form_returns_plate_carrier_intro(): void
    {
        $intro = $this->callIntro('плитоноска');
        $this->assertStringContainsString('плитоноск', mb_strtolower($intro));
    }

    #[Test]
    public function noski_still_returns_socks_intro(): void
    {
        $intro = $this->callIntro('носки');
        $this->assertStringContainsString('шкарпетки', mb_strtolower($intro));
    }

    #[Test]
    public function shkarpetky_returns_socks_intro(): void
    {
        $intro = $this->callIntro('шкарпетки');
        $this->assertStringContainsString('шкарпетки', mb_strtolower($intro));
    }
}
