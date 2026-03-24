<?php

namespace Tests\Feature;

use App\Livewire\BetaSurvey;
use App\Models\SurveyResponse;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BetaSurveyTest extends TestCase
{
    use RefreshDatabase;

    public function test_survey_page_loads(): void
    {
        $tenant = Tenant::create(['name' => 'TestShop', 'slug' => 'testshop', 'email' => 'test@test.com']);

        $this->get('/survey/testshop')->assertStatus(200);
    }

    public function test_survey_page_returns_404_for_invalid_slug(): void
    {
        $this->get('/survey/nonexistent')->assertStatus(404);
    }

    public function test_survey_can_be_submitted(): void
    {
        $tenant = Tenant::create(['name' => 'TestShop', 'slug' => 'testshop', 'email' => 'test@test.com']);

        Livewire::test(BetaSurvey::class, ['tenant' => $tenant])
            ->set('overall_rating', 4)
            ->set('recommendation_accuracy', 'mostly')
            ->set('problems', ['slow', 'repetitive'])
            ->set('tone_feedback', 'friendly')
            ->set('business_impact', ['orders_via_chat'])
            ->set('best_feature', 'fast_search')
            ->set('missing_feature', 'messengers')
            ->set('willingness_to_pay', 'after_fixes')
            ->set('nps_score', 8)
            ->set('open_comment', 'Класний чат!')
            ->call('submit')
            ->assertSet('submitted', true);

        $this->assertDatabaseHas('survey_responses', [
            'tenant_id' => $tenant->id,
            'overall_rating' => 4,
            'recommendation_accuracy' => 'mostly',
            'nps_score' => 8,
        ]);
    }

    public function test_survey_requires_mandatory_fields(): void
    {
        $tenant = Tenant::create(['name' => 'TestShop', 'slug' => 'testshop', 'email' => 'test@test.com']);

        Livewire::test(BetaSurvey::class, ['tenant' => $tenant])
            ->call('submit')
            ->assertHasErrors(['overall_rating', 'recommendation_accuracy', 'tone_feedback', 'best_feature', 'missing_feature', 'willingness_to_pay', 'nps_score']);
    }

    public function test_survey_stores_correct_json_arrays(): void
    {
        $tenant = Tenant::create(['name' => 'TestShop', 'slug' => 'testshop', 'email' => 'test@test.com']);

        Livewire::test(BetaSurvey::class, ['tenant' => $tenant])
            ->set('overall_rating', 5)
            ->set('recommendation_accuracy', 'always')
            ->set('problems', ['none'])
            ->set('tone_feedback', 'normal')
            ->set('business_impact', ['less_direct', 'orders_via_chat'])
            ->set('best_feature', '24_7')
            ->set('missing_feature', 'satisfied')
            ->set('willingness_to_pay', 'ready')
            ->set('nps_score', 10)
            ->call('submit');

        $response = SurveyResponse::first();
        $this->assertEquals(['none'], $response->problems);
        $this->assertEquals(['less_direct', 'orders_via_chat'], $response->business_impact);
    }
}
