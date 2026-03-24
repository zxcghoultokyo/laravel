<?php

namespace App\Livewire;

use App\Models\SurveyResponse;
use App\Models\Tenant;
use Livewire\Component;

class BetaSurvey extends Component
{
    public Tenant $tenant;

    public bool $submitted = false;

    // Q1 - Overall rating (1-5)
    public int $overall_rating = 0;

    // Q2 - Recommendation accuracy
    public string $recommendation_accuracy = '';

    // Q3 - Problems (checkboxes)
    public array $problems = [];

    // Q4 - Tone feedback
    public string $tone_feedback = '';

    // Q5 - Business impact (checkboxes)
    public array $business_impact = [];

    // Q6 - Best feature
    public string $best_feature = '';

    // Q7 - Missing feature
    public string $missing_feature = '';

    // Q8 - Willingness to pay
    public string $willingness_to_pay = '';

    // Q9 - NPS (0-10)
    public int $nps_score = -1;

    // Q10 - Open comment
    public string $open_comment = '';

    public function mount(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function submit(): void
    {
        $this->validate([
            'overall_rating' => 'required|integer|between:1,5',
            'recommendation_accuracy' => 'required|string',
            'problems' => 'array',
            'tone_feedback' => 'required|string',
            'business_impact' => 'array',
            'best_feature' => 'required|string',
            'missing_feature' => 'required|string',
            'willingness_to_pay' => 'required|string',
            'nps_score' => 'required|integer|between:0,10',
        ]);

        SurveyResponse::create([
            'tenant_id' => $this->tenant->id,
            'overall_rating' => $this->overall_rating,
            'recommendation_accuracy' => $this->recommendation_accuracy,
            'problems' => $this->problems,
            'tone_feedback' => $this->tone_feedback,
            'business_impact' => $this->business_impact,
            'best_feature' => $this->best_feature,
            'missing_feature' => $this->missing_feature,
            'willingness_to_pay' => $this->willingness_to_pay,
            'nps_score' => $this->nps_score,
            'open_comment' => $this->open_comment,
        ]);

        $this->submitted = true;
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('livewire.beta-survey')
            ->layout('layouts.guest');
    }
}
