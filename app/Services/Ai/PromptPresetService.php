<?php

namespace App\Services\Ai;

use App\Models\PromptPreset;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Prompt Preset Service
 * 
 * Керує вибором та рендерингом кастомних промптів.
 */
class PromptPresetService
{
    private const CACHE_KEY = 'prompt_presets_active';
    private const CACHE_TTL = 300; // 5 хвилин

    /**
     * Отримати активні пресети.
     */
    public function getActivePresets(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return PromptPreset::active()->get()->toArray();
        });
    }

    /**
     * Знайти пресет для контексту.
     */
    public function findForContext(array $context): ?array
    {
        $presets = $this->getActivePresets();

        foreach ($presets as $preset) {
            if ($this->matchesContext($preset, $context)) {
                return $preset;
            }
        }

        // Fallback to default
        $default = PromptPreset::where('is_default', true)->first();
        return $default?->toArray();
    }

    /**
     * Перевірити чи пресет підходить до контексту.
     */
    private function matchesContext(array $preset, array $context): bool
    {
        // Check language
        if (!empty($preset['language']) && isset($context['language'])) {
            if ($preset['language'] !== $context['language']) {
                return false;
            }
        }

        // Check tone
        if (!empty($preset['tone']) && isset($context['tone'])) {
            if ($preset['tone'] !== $context['tone']) {
                return false;
            }
        }

        // Check campaign (UTM)
        if (!empty($preset['campaign']) && isset($context['campaign'])) {
            if (stripos($context['campaign'], $preset['campaign']) === false) {
                return false;
            }
        }

        // Check categories
        if (!empty($preset['categories']) && isset($context['category'])) {
            $categoryMatched = false;
            foreach ($preset['categories'] as $cat) {
                if (stripos($context['category'], $cat) !== false) {
                    $categoryMatched = true;
                    break;
                }
            }
            if (!$categoryMatched) {
                return false;
            }
        }

        return true;
    }

    /**
     * Рендерити промпт з підстановкою змінних.
     */
    public function render(array $preset, array $values = []): string
    {
        $prompt = $preset['system_prompt'];

        // Get variable defaults
        $defaults = [];
        foreach ($preset['variables'] ?? [] as $var) {
            $defaults[$var['name']] = $var['default'] ?? '';
        }

        // Merge with provided values
        $values = array_merge($defaults, $values);

        // Replace {{variable}} patterns
        foreach ($values as $key => $value) {
            $prompt = str_replace('{{' . $key . '}}', $value, $prompt);
        }

        return $prompt;
    }

    /**
     * Отримати системний промпт для контексту.
     * 
     * Використовується агентами для отримання кастомного промпту
     * якщо він існує, або null для використання дефолтного.
     */
    public function getSystemPromptForContext(array $context, array $values = []): ?string
    {
        try {
            $preset = $this->findForContext($context);

            if (!$preset) {
                return null;
            }

            // Only use custom preset if it's not the default
            // or if it has specific conditions
            if ($preset['is_default'] && 
                empty($preset['language']) && 
                empty($preset['tone']) && 
                empty($preset['campaign']) && 
                empty($preset['categories'])) {
                return null;
            }

            Log::info('Using custom prompt preset', [
                'preset' => $preset['name'],
                'context' => $context,
            ]);

            return $this->render($preset, $values);
            
        } catch (\Throwable $e) {
            Log::warning('Failed to get prompt preset', [
                'error' => $e->getMessage(),
                'context' => $context,
            ]);
            return null;
        }
    }

    /**
     * Очистити кеш пресетів.
     */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
