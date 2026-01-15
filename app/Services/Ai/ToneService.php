<?php

namespace App\Services\Ai;

use App\Models\WidgetSettings;
use Illuminate\Support\Facades\Cache;

/**
 * Service for managing AI response tone and brand rules.
 * Integrates with system prompts for FunctionCallingAgent and StreamingFunctionCallingAgent.
 */
class ToneService
{
    /**
     * Tone definitions with Ukrainian descriptions and prompt modifiers.
     */
    private const TONES = [
        'official' => [
            'name' => 'Офіційний',
            'description' => 'Ввічливий, формальний стиль',
            'prompt' => <<<TONE
СТИЛЬ СПІЛКУВАННЯ (ОФІЦІЙНИЙ):
- Звертайся на "Ви"
- Використовуй ввічливі форми: "Будь ласка", "Дякую за запит", "Дозвольте запропонувати"
- Тримай формальний, професійний тон
- Уникай сленгу та неформальних виразів
- Приклад: "Доброго дня! Дозвольте запропонувати Вам декілька варіантів."
TONE,
            'example_question' => 'Порадьте теплу куртку до −15°C',
            'example_answer' => 'Доброго дня! Дозвольте запропонувати Вам декілька варіантів теплих курток, розрахованих на температуру до −15°C. Чи є у Вас переваги щодо бренду?',
        ],
        'spartan' => [
            'name' => 'Лаконічний',
            'description' => 'Коротко, по суті',
            'prompt' => <<<TONE
СТИЛЬ СПІЛКУВАННЯ (ЛАКОНІЧНИЙ):
- Максимально коротко — 1-2 речення
- Без "води", лише факти
- Без зайвих ввічливостей
- Можна на "ти" якщо клієнт почав так
- Приклад: "Ось варіанти. Який розмір?"
TONE,
            'example_question' => 'Порадьте теплу куртку до −15°C',
            'example_answer' => 'Три варіанти під -15°C. Який розмір?',
        ],
        'friendly' => [
            'name' => 'Дружній',
            'description' => 'Неформальний, позитивний',
            'prompt' => <<<TONE
СТИЛЬ СПІЛКУВАННЯ (ДРУЖНІЙ):
- Неформальний, позитивний тон
- Можна на "ти"
- Використовуй emoji помірковано (1-2 на повідомлення)
- Будь ентузіастичним, але не нав'язливим
- Приклад: "О, крута задача! 🔥 Є кілька топових варіантів!"
TONE,
            'example_question' => 'Порадьте теплу куртку до −15°C',
            'example_answer' => 'О, крута задача! 🔥 Є декілька топових варіантів під -15. Який бюджет орієнтовно?',
        ],
    ];

    /**
     * Get tone prompt modifier for system prompt.
     */
    public function getTonePrompt(?string $tone = null): string
    {
        $tone = $tone ?? $this->getStoreTone();
        
        return self::TONES[$tone]['prompt'] ?? self::TONES['official']['prompt'];
    }

    /**
     * Get brand rules formatted for system prompt.
     */
    public function getBrandRulesPrompt(?array $rules = null): string
    {
        $rules = $rules ?? $this->getStoreBrandRules();
        
        if (empty($rules)) {
            return '';
        }

        // Filter out empty rules
        $rules = array_filter($rules, fn($rule) => !empty(trim($rule)));
        
        if (empty($rules)) {
            return '';
        }

        $rulesList = implode("\n", array_map(
            fn($rule, $i) => ($i + 1) . ". " . trim($rule),
            array_values($rules),
            array_keys($rules)
        ));

        return <<<RULES

ПРАВИЛА БРЕНДУ (ОБОВ'ЯЗКОВО ДОТРИМУЙСЯ!):
{$rulesList}
RULES;
    }

    /**
     * Get full tone and brand rules section for system prompt.
     */
    public function getFullPromptSection(?string $tone = null, ?array $brandRules = null): string
    {
        $tonePrompt = $this->getTonePrompt($tone);
        $brandRulesPrompt = $this->getBrandRulesPrompt($brandRules);

        return $tonePrompt . $brandRulesPrompt;
    }

    /**
     * Get all available tones with metadata.
     */
    public function getAvailableTones(): array
    {
        return collect(self::TONES)->map(fn($tone, $key) => [
            'key' => $key,
            'name' => $tone['name'],
            'description' => $tone['description'],
            'example_question' => $tone['example_question'],
            'example_answer' => $tone['example_answer'],
        ])->values()->all();
    }

    /**
     * Get tone info by key.
     */
    public function getToneInfo(string $tone): ?array
    {
        if (!isset(self::TONES[$tone])) {
            return null;
        }

        return [
            'key' => $tone,
            'name' => self::TONES[$tone]['name'],
            'description' => self::TONES[$tone]['description'],
            'example_question' => self::TONES[$tone]['example_question'],
            'example_answer' => self::TONES[$tone]['example_answer'],
        ];
    }

    /**
     * Get current store tone from settings.
     */
    public function getStoreTone(): string
    {
        $settings = $this->getSettings();
        return $settings?->tone ?? 'official';
    }

    /**
     * Get current store brand rules from settings.
     */
    public function getStoreBrandRules(): array
    {
        $settings = $this->getSettings();
        return $settings?->brand_rules ?? [];
    }

    /**
     * Get cached widget settings.
     */
    private function getSettings(): ?WidgetSettings
    {
        return Cache::remember('widget_settings_tone', 300, function () {
            return WidgetSettings::first();
        });
    }
}
