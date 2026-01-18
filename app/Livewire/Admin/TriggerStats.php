<?php

namespace App\Livewire\Admin;

use App\Models\ProactiveTriggerRule;
use App\Models\ProactiveTriggerEvent;
use Livewire\Component;
use Carbon\Carbon;

class TriggerStats extends Component
{
    // Embedded mode (in tenant dashboard)
    public bool $embedded = false;
    public ?int $tenantId = null;

    // Filters
    public $period = '7d';
    public $triggerType = '';
    public $ruleId = '';

    // Date range for custom period
    public $dateFrom = '';
    public $dateTo = '';

    public function mount(bool $embedded = false)
    {
        $this->embedded = $embedded;
        
        // Set tenant from auth user when embedded
        if ($this->embedded && auth()->check() && auth()->user()->tenant_id) {
            $this->tenantId = auth()->user()->tenant_id;
        }
        
        $this->dateFrom = now()->subDays(7)->format('Y-m-d');
        $this->dateTo = now()->format('Y-m-d');
    }

    public function render()
    {
        $dateRange = $this->getDateRange();
        
        // Get all rules for filter dropdown (filtered by tenant if set)
        $rulesQuery = ProactiveTriggerRule::orderBy('name');
        if ($this->tenantId) {
            $rulesQuery->where('tenant_id', $this->tenantId);
        }
        $rules = $rulesQuery->get();
        
        // Get trigger types
        $triggerTypes = [
            'exit_intent' => '🚪 Exit Intent',
            'time_on_page' => '⏱️ Time on Page',
            'utm_campaign' => '🎯 UTM Campaign',
            'returning_visitor' => '🔄 Returning Visitor',
            'pdp_no_variant' => '🛍️ PDP No Variant',
        ];

        // Get funnel data
        $funnelData = $this->getFunnelData($dateRange);
        
        // Get per-rule stats
        $rulesStats = $this->getRulesStats($dateRange);
        
        // Get trend data for chart
        $trendData = $this->getTrendData($dateRange);
        
        // Get top performing rules
        $topRules = $this->getTopRules($dateRange);

        $view = view('livewire.admin.trigger-stats', [
            'rules' => $rules,
            'triggerTypes' => $triggerTypes,
            'funnelData' => $funnelData,
            'rulesStats' => $rulesStats,
            'trendData' => $trendData,
            'topRules' => $topRules,
            'dateRange' => $dateRange,
        ]);

        return $this->embedded ? $view : $view->layout('admin.layout');
    }

    protected function getDateRange(): array
    {
        switch ($this->period) {
            case 'today':
                return [
                    'from' => now()->startOfDay(),
                    'to' => now()->endOfDay(),
                    'label' => 'Сьогодні',
                ];
            case '7d':
                return [
                    'from' => now()->subDays(7)->startOfDay(),
                    'to' => now()->endOfDay(),
                    'label' => 'Останні 7 днів',
                ];
            case '30d':
                return [
                    'from' => now()->subDays(30)->startOfDay(),
                    'to' => now()->endOfDay(),
                    'label' => 'Останні 30 днів',
                ];
            case 'custom':
                return [
                    'from' => Carbon::parse($this->dateFrom)->startOfDay(),
                    'to' => Carbon::parse($this->dateTo)->endOfDay(),
                    'label' => "{$this->dateFrom} - {$this->dateTo}",
                ];
            default:
                return [
                    'from' => now()->subDays(7)->startOfDay(),
                    'to' => now()->endOfDay(),
                    'label' => 'Останні 7 днів',
                ];
        }
    }

    protected function getFunnelData(array $dateRange): array
    {
        $query = ProactiveTriggerEvent::whereBetween('created_at', [$dateRange['from'], $dateRange['to']]);
        
        // Filter by tenant
        if ($this->tenantId) {
            $query->whereHas('rule', fn($q) => $q->where('tenant_id', $this->tenantId));
        }
        
        if ($this->triggerType) {
            $query->whereHas('rule', fn($q) => $q->where('trigger_type', $this->triggerType));
        }
        
        if ($this->ruleId) {
            $query->where('rule_id', $this->ruleId);
        }

        $events = $query->get();

        $shown = $events->where('event_type', 'shown')->count();
        $clicked = $events->where('event_type', 'clicked')->count();
        $productViewed = $events->where('event_type', 'product_viewed')->count();
        $addedToCart = $events->where('event_type', 'added_to_cart')->count();
        $purchased = $events->where('event_type', 'purchased')->count();

        return [
            'shown' => [
                'count' => $shown,
                'rate' => 100,
                'label' => 'Показано',
                'icon' => '👁️',
                'color' => 'blue',
            ],
            'clicked' => [
                'count' => $clicked,
                'rate' => $shown > 0 ? round(($clicked / $shown) * 100, 1) : 0,
                'label' => 'Клікнуто',
                'icon' => '👆',
                'color' => 'indigo',
            ],
            'product_viewed' => [
                'count' => $productViewed,
                'rate' => $clicked > 0 ? round(($productViewed / $clicked) * 100, 1) : 0,
                'label' => 'Переглянуто товар',
                'icon' => '🔍',
                'color' => 'purple',
            ],
            'added_to_cart' => [
                'count' => $addedToCart,
                'rate' => $productViewed > 0 ? round(($addedToCart / $productViewed) * 100, 1) : 0,
                'label' => 'Додано в кошик',
                'icon' => '🛒',
                'color' => 'orange',
            ],
            'purchased' => [
                'count' => $purchased,
                'rate' => $addedToCart > 0 ? round(($purchased / $addedToCart) * 100, 1) : 0,
                'label' => 'Замовлено',
                'icon' => '✅',
                'color' => 'green',
            ],
        ];
    }

    protected function getRulesStats(array $dateRange): \Illuminate\Support\Collection
    {
        $query = ProactiveTriggerRule::query();
        
        // Filter by tenant
        if ($this->tenantId) {
            $query->where('tenant_id', $this->tenantId);
        }
        
        if ($this->triggerType) {
            $query->where('trigger_type', $this->triggerType);
        }
        
        if ($this->ruleId) {
            $query->where('id', $this->ruleId);
        }

        return $query->get()->map(function ($rule) use ($dateRange) {
            $events = $rule->events()
                ->whereBetween('created_at', [$dateRange['from'], $dateRange['to']])
                ->get();

            $shown = $events->where('event_type', 'shown')->count();
            $clicked = $events->where('event_type', 'clicked')->count();
            $converted = $events->where('event_type', 'added_to_cart')->count();
            $purchased = $events->where('event_type', 'purchased')->count();

            return [
                'id' => $rule->id,
                'name' => $rule->name,
                'type' => $rule->trigger_type,
                'is_enabled' => $rule->is_enabled,
                'shown' => $shown,
                'clicked' => $clicked,
                'converted' => $converted,
                'purchased' => $purchased,
                'ctr' => $shown > 0 ? round(($clicked / $shown) * 100, 1) : 0,
                'conversion_rate' => $clicked > 0 ? round(($converted / $clicked) * 100, 1) : 0,
                'purchase_rate' => $shown > 0 ? round(($purchased / $shown) * 100, 2) : 0,
            ];
        })->sortByDesc('shown');
    }

    protected function getTrendData(array $dateRange): array
    {
        $days = [];
        $current = $dateRange['from']->copy();
        
        while ($current <= $dateRange['to']) {
            $dayStart = $current->copy()->startOfDay();
            $dayEnd = $current->copy()->endOfDay();
            
            $query = ProactiveTriggerEvent::whereBetween('created_at', [$dayStart, $dayEnd]);
            
            // Filter by tenant
            if ($this->tenantId) {
                $query->whereHas('rule', fn($q) => $q->where('tenant_id', $this->tenantId));
            }
            
            if ($this->triggerType) {
                $query->whereHas('rule', fn($q) => $q->where('trigger_type', $this->triggerType));
            }
            
            if ($this->ruleId) {
                $query->where('rule_id', $this->ruleId);
            }

            $events = $query->get();

            $days[] = [
                'date' => $current->format('d.m'),
                'shown' => $events->where('event_type', 'shown')->count(),
                'clicked' => $events->where('event_type', 'clicked')->count(),
                'converted' => $events->where('event_type', 'added_to_cart')->count(),
            ];
            
            $current->addDay();
        }

        return $days;
    }

    protected function getTopRules(array $dateRange): \Illuminate\Support\Collection
    {
        $query = ProactiveTriggerRule::where('is_enabled', true);
        
        // Filter by tenant
        if ($this->tenantId) {
            $query->where('tenant_id', $this->tenantId);
        }
        
        return $query->get()
            ->map(function ($rule) use ($dateRange) {
                $events = $rule->events()
                    ->whereBetween('created_at', [$dateRange['from'], $dateRange['to']])
                    ->get();

                $shown = $events->where('event_type', 'shown')->count();
                $clicked = $events->where('event_type', 'clicked')->count();

                return [
                    'id' => $rule->id,
                    'name' => $rule->name,
                    'type' => $rule->trigger_type,
                    'shown' => $shown,
                    'clicked' => $clicked,
                    'ctr' => $shown > 0 ? round(($clicked / $shown) * 100, 1) : 0,
                ];
            })
            ->sortByDesc('ctr')
            ->take(5);
    }

    public function updatedPeriod()
    {
        if ($this->period !== 'custom') {
            $range = $this->getDateRange();
            $this->dateFrom = $range['from']->format('Y-m-d');
            $this->dateTo = $range['to']->format('Y-m-d');
        }
    }
}
