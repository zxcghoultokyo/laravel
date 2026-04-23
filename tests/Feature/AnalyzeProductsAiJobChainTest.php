<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeProductsWithAiJob;
use App\Models\Tenant;
use App\Models\TenantOnboardingProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards the "stuck enrichment" prod bug where AnalyzeProductsWithAiJob hit
 * a TimeoutExceededException on a large batch (batchSize=50), exhausted its
 * tries=3 retries and landed in failed_jobs — but the auto-dispatch of the
 * next batch lives at the tail of processAnalysis() and therefore never ran.
 * Result: onboarding stuck forever at 50/780.
 *
 * Fix: implement failed(Throwable) to re-dispatch a smaller batch.
 */
class AnalyzeProductsAiJobChainTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(): Tenant
    {
        return Tenant::create([
            'name' => 'Test Store',
            'slug' => 'test-'.uniqid(),
            'status' => 'active',
        ]);
    }

    #[Test]
    public function failed_method_redispatches_next_batch_with_smaller_size(): void
    {
        Queue::fake();

        $tenant = $this->makeTenant();

        $job = new AnalyzeProductsWithAiJob(
            batchSize: 20,
            offset: 0,
            forceReanalyze: false,
            tenantId: $tenant->id,
            singleBatchOnly: false
        );

        $job->failed(new \Illuminate\Queue\TimeoutExceededException('timed out'));

        Queue::assertPushed(AnalyzeProductsWithAiJob::class, function ($pushed) use ($tenant) {
            return $pushed->tenantId === $tenant->id
                && $pushed->batchSize === 10 // halved from 20
                && $pushed->offset === 0
                && $pushed->singleBatchOnly === false;
        });
    }

    #[Test]
    public function failed_method_does_not_redispatch_when_single_batch_only(): void
    {
        Queue::fake();

        $tenant = $this->makeTenant();

        $job = new AnalyzeProductsWithAiJob(
            batchSize: 20,
            offset: 0,
            forceReanalyze: false,
            tenantId: $tenant->id,
            singleBatchOnly: true
        );

        $job->failed(new \RuntimeException('boom'));

        Queue::assertNotPushed(AnalyzeProductsWithAiJob::class);
    }

    #[Test]
    public function failed_method_does_not_redispatch_when_no_tenant(): void
    {
        Queue::fake();

        $job = new AnalyzeProductsWithAiJob(
            batchSize: 20,
            offset: 0,
            forceReanalyze: false,
            tenantId: null,
            singleBatchOnly: false
        );

        $job->failed(new \RuntimeException('boom'));

        Queue::assertNotPushed(AnalyzeProductsWithAiJob::class);
    }

    #[Test]
    public function failed_method_respects_minimum_batch_size(): void
    {
        Queue::fake();

        $tenant = $this->makeTenant();

        // batchSize=5 halved = 2 but floor of min(5) keeps it at 5
        $job = new AnalyzeProductsWithAiJob(
            batchSize: 5,
            offset: 0,
            forceReanalyze: false,
            tenantId: $tenant->id,
            singleBatchOnly: false
        );

        $job->failed(new \RuntimeException('boom'));

        Queue::assertPushed(AnalyzeProductsWithAiJob::class, function ($pushed) {
            return $pushed->batchSize === 5;
        });
    }

    #[Test]
    public function failed_method_records_error_on_onboarding_progress(): void
    {
        Queue::fake();

        $tenant = $this->makeTenant();

        $progress = TenantOnboardingProgress::create([
            'tenant_id' => $tenant->id,
            'status' => 'in_progress',
            'overall_percent' => 50,
            'steps' => [
                'ai_enrichment' => [
                    'status' => 'in_progress',
                    'percent' => 10,
                    'detail' => 'Processing...',
                    'stats' => [],
                    'started_at' => now()->toIso8601String(),
                    'completed_at' => null,
                ],
            ],
        ]);

        $job = new AnalyzeProductsWithAiJob(
            batchSize: 20,
            offset: 0,
            forceReanalyze: false,
            tenantId: $tenant->id,
            singleBatchOnly: false
        );

        $job->failed(new \Illuminate\Queue\TimeoutExceededException('has timed out'));

        $progress->refresh();
        $aiStep = $progress->steps['ai_enrichment'] ?? null;

        $this->assertNotNull($aiStep);
        $this->assertStringContainsString('продовжуємо', $aiStep['detail'] ?? '');
        $this->assertSame('TimeoutExceededException', $aiStep['stats']['last_batch_error'] ?? null);
    }
}
