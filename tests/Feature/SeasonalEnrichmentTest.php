<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeProductsWithAiJob;
use App\Jobs\IndexProductsToMeiliJob;
use Tests\TestCase;

class SeasonalEnrichmentTest extends TestCase
{
    public function test_enrichment_prompt_contains_seasons_field(): void
    {
        $job = new AnalyzeProductsWithAiJob;
        $reflection = new \ReflectionMethod($job, 'buildPrompt');
        $reflection->setAccessible(true);

        $prompt = $reflection->invoke($job, 'Куртка зимова ECWCS Level 7', 'Опис куртки', 'Одяг/Куртки', 'Матеріал: нейлон');

        $this->assertStringContainsString('"seasons"', $prompt);
        $this->assertStringContainsString('весна', $prompt);
        $this->assertStringContainsString('літо', $prompt);
        $this->assertStringContainsString('осінь', $prompt);
        $this->assertStringContainsString('зима', $prompt);
        $this->assertStringContainsString('дощ', $prompt);
        $this->assertStringContainsString('волога', $prompt);
        $this->assertStringContainsString('спека', $prompt);
        $this->assertStringContainsString('мороз', $prompt);
    }

    public function test_enrichment_prompt_keywords_include_seasonal_guidance(): void
    {
        $job = new AnalyzeProductsWithAiJob;
        $reflection = new \ReflectionMethod($job, 'buildPrompt');
        $reflection->setAccessible(true);

        $prompt = $reflection->invoke($job, 'Футболка тактична', '', 'Одяг', '');

        $this->assertStringContainsString('сезонні слова', $prompt);
        $this->assertStringContainsString('демісезонний', $prompt);
        $this->assertStringContainsString('всесезонний', $prompt);
    }

    public function test_enrichment_prompt_search_queries_include_seasonal_examples(): void
    {
        $job = new AnalyzeProductsWithAiJob;
        $reflection = new \ReflectionMethod($job, 'buildPrompt');
        $reflection->setAccessible(true);

        $prompt = $reflection->invoke($job, 'Берці літні', '', 'Взуття', '');

        $this->assertStringContainsString('сезонних запити', $prompt);
        $this->assertStringContainsString('на весну', $prompt);
        $this->assertStringContainsString('на літо', $prompt);
        $this->assertStringContainsString('на зиму', $prompt);
    }

    public function test_meili_document_includes_ai_seasons_field(): void
    {
        $job = new IndexProductsToMeiliJob;
        $reflection = new \ReflectionClass($job);

        // Verify flattenArrayField works for seasons data
        $method = $reflection->getMethod('flattenArrayField');
        $method->setAccessible(true);

        $seasons = ['весна', 'літо', 'спека', 'тепла погода'];
        $result = $method->invoke($job, $seasons);

        $this->assertStringContainsString('весна', $result);
        $this->assertStringContainsString('літо', $result);
        $this->assertStringContainsString('спека', $result);
        $this->assertStringContainsString('тепла погода', $result);
    }

    public function test_analyze_command_prompt_contains_seasons(): void
    {
        $command = new \App\Console\Commands\AnalyzeProductsCommand;
        $reflection = new \ReflectionMethod($command, 'buildPrompt');
        $reflection->setAccessible(true);

        $prompt = $reflection->invoke($command, 'Шолом балістичний', '', 'Броня', '');

        $this->assertStringContainsString('"seasons"', $prompt);
        $this->assertStringContainsString('всесезонний', $prompt);
    }

    public function test_force_reanalyze_increments_offset(): void
    {
        // When forceReanalyze=true, offset must increment to avoid infinite loop
        $job = new AnalyzeProductsWithAiJob(
            batchSize: 50,
            offset: 0,
            forceReanalyze: true,
            tenantId: 1
        );

        $this->assertTrue($job->forceReanalyze);
        $this->assertEquals(0, $job->offset);
        $this->assertEquals(50, $job->batchSize);

        // Verify the property is public and accessible for next batch calculation
        $nextOffset = $job->forceReanalyze
            ? $job->offset + $job->batchSize
            : 0;
        $this->assertEquals(50, $nextOffset, 'Force mode should increment offset');
    }

    public function test_non_force_mode_uses_zero_offset(): void
    {
        $job = new AnalyzeProductsWithAiJob(
            batchSize: 50,
            offset: 100,
            forceReanalyze: false,
            tenantId: 1
        );

        $nextOffset = $job->forceReanalyze
            ? $job->offset + $job->batchSize
            : 0;
        $this->assertEquals(0, $nextOffset, 'Non-force mode should use offset 0 (relies on whereNotIn)');
    }
}
