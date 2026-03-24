<?php

namespace App\Livewire\Admin;

use App\Models\SurveyResponse;
use App\Models\Tenant;
use Livewire\Component;

class SurveyResults extends Component
{
    public int $selectedTenantId = 0;

    public array $tenants = [];

    public function mount(): void
    {
        $this->tenants = Tenant::orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        $query = SurveyResponse::query()->with('tenant');

        if ($this->selectedTenantId) {
            $query->where('tenant_id', $this->selectedTenantId);
        }

        $responses = $query->latest()->get();

        $stats = $this->calculateStats($responses);

        return view('livewire.admin.survey-results', [
            'responses' => $responses,
            'stats' => $stats,
        ]);
    }

    private function calculateStats($responses): array
    {
        if ($responses->isEmpty()) {
            return [];
        }

        $count = $responses->count();

        // NPS calculation
        $promoters = $responses->where('nps_score', '>=', 9)->count();
        $detractors = $responses->where('nps_score', '<=', 6)->count();
        $nps = round(($promoters - $detractors) / $count * 100);

        // Average rating
        $avgRating = round($responses->avg('overall_rating'), 1);

        // Top problems
        $allProblems = $responses->pluck('problems')->flatten()->filter()->countBy()->sortDesc();

        // Payment readiness
        $paymentReady = $responses->whereIn('willingness_to_pay', ['ready', 'after_fixes'])->count();

        return [
            'count' => $count,
            'avg_rating' => $avgRating,
            'nps' => $nps,
            'promoters' => $promoters,
            'passives' => $count - $promoters - $detractors,
            'detractors' => $detractors,
            'top_problems' => $allProblems->take(5)->toArray(),
            'payment_ready_percent' => round($paymentReady / $count * 100),
        ];
    }
}
