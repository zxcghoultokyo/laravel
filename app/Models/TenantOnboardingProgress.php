<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Track onboarding progress for tenants
 * 
 * @property int $id
 * @property int $tenant_id
 * @property string $status pending|in_progress|completed|failed
 * @property string|null $current_step
 * @property string|null $current_step_detail
 * @property int $overall_percent
 * @property array|null $steps
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $completed_at
 * @property string|null $error_message
 */
class TenantOnboardingProgress extends Model
{
    protected $table = 'tenant_onboarding_progress';

    protected $fillable = [
        'tenant_id',
        'status',
        'current_step',
        'current_step_detail',
        'overall_percent',
        'steps',
        'started_at',
        'completed_at',
        'error_message',
    ];

    protected $casts = [
        'overall_percent' => 'integer',
        'steps' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Step definitions with weights for progress calculation
     */
    public const STEPS = [
        'horoshop_sync' => [
            'name' => 'Синхронізація товарів',
            'weight' => 25,
            'substeps' => [
                'connecting' => 'Підключення до Horoshop API',
                'fetching' => 'Завантаження товарів',
                'saving' => 'Збереження в базу даних',
            ],
        ],
        'categories_rebuild' => [
            'name' => 'Побудова категорій',
            'weight' => 10,
            'substeps' => [
                'extracting' => 'Витягування категорій з товарів',
                'building' => 'Побудова дерева категорій',
            ],
        ],
        'brands_sync' => [
            'name' => 'Синхронізація брендів',
            'weight' => 5,
            'substeps' => [
                'extracting' => 'Витягування брендів',
                'saving' => 'Збереження брендів',
            ],
        ],
        'ai_enrichment' => [
            'name' => 'AI збагачення товарів',
            'weight' => 40,
            'substeps' => [
                'analyzing' => 'Аналіз товарів',
                'keywords' => 'Генерація ключових слів',
                'slang' => 'Створення сленгових синонімів',
                'categories' => 'AI категоризація',
            ],
        ],
        'meili_indexing' => [
            'name' => 'Індексація пошуку',
            'weight' => 20,
            'substeps' => [
                'preparing' => 'Підготовка документів',
                'indexing' => 'Індексація в Meilisearch',
                'settings' => 'Налаштування пошуку',
            ],
        ],
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get or create progress record for tenant
     */
    public static function forTenant(int $tenantId): self
    {
        return self::firstOrCreate(
            ['tenant_id' => $tenantId],
            [
                'status' => 'pending',
                'overall_percent' => 0,
                'steps' => self::initializeSteps(),
            ]
        );
    }

    /**
     * Initialize steps structure
     */
    public static function initializeSteps(): array
    {
        $steps = [];
        foreach (self::STEPS as $key => $step) {
            $steps[$key] = [
                'status' => 'pending', // pending, in_progress, completed, failed
                'percent' => 0,
                'detail' => null,
                'started_at' => null,
                'completed_at' => null,
                'stats' => [], // Step-specific stats like products_count, etc.
            ];
        }
        return $steps;
    }

    /**
     * Start onboarding
     */
    public function start(): self
    {
        $this->update([
            'status' => 'in_progress',
            'started_at' => now(),
            'steps' => self::initializeSteps(),
            'overall_percent' => 0,
            'current_step' => null,
            'current_step_detail' => null,
            'error_message' => null,
        ]);
        return $this;
    }

    /**
     * Update step progress
     */
    public function updateStep(
        string $step,
        string $status,
        int $percent = 0,
        ?string $detail = null,
        array $stats = []
    ): self {
        $steps = $this->steps ?? self::initializeSteps();
        
        if (!isset($steps[$step])) {
            return $this;
        }

        $steps[$step]['status'] = $status;
        $steps[$step]['percent'] = min(100, max(0, $percent));
        $steps[$step]['detail'] = $detail;
        
        if (!empty($stats)) {
            $steps[$step]['stats'] = array_merge($steps[$step]['stats'] ?? [], $stats);
        }

        if ($status === 'in_progress' && !$steps[$step]['started_at']) {
            $steps[$step]['started_at'] = now()->toIso8601String();
        }
        
        if ($status === 'completed') {
            $steps[$step]['completed_at'] = now()->toIso8601String();
            $steps[$step]['percent'] = 100;
        }

        // Calculate overall percent
        $overallPercent = $this->calculateOverallPercent($steps);

        // Get current step name for display
        $currentStepName = self::STEPS[$step]['name'] ?? $step;
        $currentDetail = $detail;
        
        if ($status === 'in_progress' && isset(self::STEPS[$step]['substeps'])) {
            // Try to find substep detail
            foreach (self::STEPS[$step]['substeps'] as $substepKey => $substepName) {
                if (str_contains(strtolower($detail ?? ''), $substepKey)) {
                    $currentDetail = $substepName;
                    break;
                }
            }
        }

        $this->update([
            'steps' => $steps,
            'overall_percent' => $overallPercent,
            'current_step' => $currentStepName,
            'current_step_detail' => $currentDetail,
        ]);

        return $this;
    }

    /**
     * Calculate overall percent from steps
     */
    protected function calculateOverallPercent(array $steps): int
    {
        $totalWeight = 0;
        $completedWeight = 0;

        foreach (self::STEPS as $key => $stepDef) {
            $weight = $stepDef['weight'];
            $totalWeight += $weight;
            
            if (isset($steps[$key])) {
                $stepPercent = $steps[$key]['percent'] ?? 0;
                $completedWeight += ($weight * $stepPercent / 100);
            }
        }

        return $totalWeight > 0 ? (int) round($completedWeight / $totalWeight * 100) : 0;
    }

    /**
     * Mark onboarding as completed
     */
    public function complete(): self
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
            'overall_percent' => 100,
            'current_step' => 'Завершено',
            'current_step_detail' => 'Онбординг успішно завершено!',
        ]);
        return $this;
    }

    /**
     * Mark onboarding as failed
     */
    public function fail(string $error): self
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $error,
            'current_step_detail' => 'Помилка: ' . $error,
        ]);
        return $this;
    }

    /**
     * Get human-readable status
     */
    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            'pending' => 'Очікує запуску',
            'in_progress' => 'В процесі',
            'completed' => 'Завершено',
            'failed' => 'Помилка',
            default => 'Невідомо',
        };
    }

    /**
     * Get progress data for API/frontend
     */
    public function toProgressArray(): array
    {
        return [
            'status' => $this->status,
            'status_label' => $this->status_label,
            'overall_percent' => $this->overall_percent,
            'current_step' => $this->current_step,
            'current_step_detail' => $this->current_step_detail,
            'steps' => collect($this->steps ?? [])->map(function ($step, $key) {
                $stepDef = self::STEPS[$key] ?? [];
                return [
                    'key' => $key,
                    'name' => $stepDef['name'] ?? $key,
                    'status' => $step['status'] ?? 'pending',
                    'percent' => $step['percent'] ?? 0,
                    'detail' => $step['detail'] ?? null,
                    'stats' => $step['stats'] ?? [],
                ];
            })->values()->toArray(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'error_message' => $this->error_message,
        ];
    }
}
