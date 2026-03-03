<?php

namespace App\Services\Ai;

use App\Models\PromptPreset;
use App\Models\StoreContext;
use App\Scopes\TenantScope;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Prompt Preset Service — Layered Multi-Tenant Prompt System
 * 
 * Шаруватий підхід: base (is_default) + overlay пресети мерджаться.
 * Кеш ізольований per-tenant. Підтримує як ручні пресети, так і автогенеровані.
 * 
 * Приклад для bavkatoys:
 *   БАЗОВИЙ (is_default) — завжди включений як основа
 *   + Скрипти частих питань (priority 80) — overlay, без фільтрів → завжди додається
 *   + Меблі (priority 90, categories: МЕБЛІ) — overlay з фільтром → лише для меблів
 */
class PromptPresetService
{
    private const CACHE_KEY_PREFIX = 'prompt_presets_active';
    private const CACHE_TTL = 300; // 5 хвилин

    /**
     * Отримати активні пресети для тенанта.
     * 
     * Кеш ізольований per-tenant. TenantScope фільтрує автоматично,
     * але ми ще й кешуємо з tenant_id в ключі для безпеки.
     */
    public function getActivePresets(?int $tenantId = null): array
    {
        $tenantId = $tenantId ?? $this->resolveTenantId();
        $cacheKey = self::CACHE_KEY_PREFIX . ':' . ($tenantId ?? 'global');

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($tenantId) {
            $query = PromptPreset::withoutGlobalScope(TenantScope::class)
                ->where('is_active', true)
                ->orderByDesc('priority');
            
            if ($tenantId) {
                $query->where('tenant_id', $tenantId);
            }
            
            return $query->get()->toArray();
        });
    }

    /**
     * Знайти шари пресетів для контексту (layered approach).
     * 
     * Повертає:
     *   ['base' => ?array, 'overlays' => array]
     * 
     * base — is_default=true пресет (один на тенант)
     * overlays — всі non-default пресети що матчать контекст, sorted by priority ASC
     *   (нижчий priority додається першим, вищий перетре)
     */
    public function findLayersForContext(array $context): array
    {
        $tenantId = $context['tenant_id'] ?? null;
        $presets = $this->getActivePresets($tenantId);

        $base = null;
        $overlays = [];

        foreach ($presets as $preset) {
            if (!empty($preset['is_default'])) {
                // Base preset — always included (one per tenant)
                $base = $preset;
                continue;
            }

            // Non-default preset — check if it matches current context
            if ($this->matchesOverlay($preset, $context)) {
                $overlays[] = $preset;
            }
        }

        // Sort overlays by priority ASC (lower priority first, higher appended last = wins)
        usort($overlays, fn($a, $b) => ($a['priority'] ?? 0) <=> ($b['priority'] ?? 0));

        return ['base' => $base, 'overlays' => $overlays];
    }

    /**
     * Legacy: знайти один пресет для контексту (backward-compatible).
     */
    public function findForContext(array $context): ?array
    {
        $layers = $this->findLayersForContext($context);
        
        // Return first overlay if any, otherwise base
        if (!empty($layers['overlays'])) {
            return end($layers['overlays']); // highest priority
        }
        
        return $layers['base'];
    }

    /**
     * Перевірити чи overlay-пресет підходить до контексту.
     * 
     * Overlay без жодних фільтрів (language, tone, campaign, categories)
     * матчить ЗАВЖДИ — це "глобальний overlay" (наприклад, Скрипти FAQ).
     * 
     * Overlay з фільтрами матчить тільки якщо фільтри збігаються.
     */
    private function matchesOverlay(array $preset, array $context): bool
    {
        $hasAnyFilter = false;

        // Check language
        if (!empty($preset['language'])) {
            $hasAnyFilter = true;
            if (isset($context['language']) && $preset['language'] !== $context['language']) {
                return false;
            }
        }

        // Check tone
        if (!empty($preset['tone'])) {
            $hasAnyFilter = true;
            if (isset($context['tone']) && $preset['tone'] !== $context['tone']) {
                return false;
            }
        }

        // Check campaign (UTM)
        if (!empty($preset['campaign'])) {
            $hasAnyFilter = true;
            if (isset($context['campaign'])) {
                if (stripos($context['campaign'], $preset['campaign']) === false) {
                    return false;
                }
            } else {
                // Campaign filter set but no campaign in context → no match
                return false;
            }
        }

        // Check categories
        if (!empty($preset['categories'])) {
            $hasAnyFilter = true;
            if (isset($context['category'])) {
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
            } else {
                // Category filter set but no category in context → no match
                return false;
            }
        }

        // All filters passed (or no filters = global overlay)
        return true;
    }

    /**
     * Рендерити промпт з підстановкою змінних.
     */
    public function render(array $preset, array $values = []): string
    {
        $prompt = $preset['system_prompt'] ?? '';

        // Get variable defaults
        $defaults = [];
        foreach ($preset['variables'] ?? [] as $var) {
            if (is_array($var) && isset($var['name'])) {
                $defaults[$var['name']] = $var['default'] ?? '';
            }
        }

        // Merge with provided values
        $values = array_merge($defaults, $values);

        // Replace {{variable}} patterns
        foreach ($values as $key => $value) {
            if (is_string($value)) {
                $prompt = str_replace('{{' . $key . '}}', $value, $prompt);
            }
        }

        return $prompt;
    }

    /**
     * Отримати системний промпт для контексту (LAYERED).
     * 
     * Мерджить base + overlays в один промпт:
     *   1. Base prompt (is_default) — основа
     *   2. Overlay prompts — додаються через розділювач
     * 
     * Якщо є хоча б base або overlays → повертає merged prompt.
     * Якщо нічого немає → null (агент використає модульний промпт).
     */
    public function getSystemPromptForContext(array $context, array $values = []): ?string
    {
        try {
            $layers = $this->findLayersForContext($context);
            $base = $layers['base'];
            $overlays = $layers['overlays'];

            // Nothing found → null (agent falls back to modular prompt)
            if (!$base && empty($overlays)) {
                return null;
            }

            $parts = [];

            // 1. Render base prompt
            if ($base && !empty($base['system_prompt'])) {
                $rendered = $this->render($base, $values);
                if (trim($rendered)) {
                    $parts[] = $rendered;
                }
            }

            // 2. Render and append overlays (sorted by priority ASC)
            foreach ($overlays as $overlay) {
                if (!empty($overlay['system_prompt'])) {
                    $rendered = $this->render($overlay, $values);
                    if (trim($rendered)) {
                        $label = $overlay['name'] ?? 'Overlay';
                        $parts[] = "--- {$label} ---\n{$rendered}";
                    }
                }
            }

            if (empty($parts)) {
                return null;
            }

            $merged = implode("\n\n", $parts);

            // Log which layers were used
            $layerNames = [];
            if ($base) {
                $layerNames[] = "base:{$base['name']}";
            }
            foreach ($overlays as $o) {
                $layerNames[] = "overlay:{$o['name']}(p{$o['priority']})";
            }

            Log::info('PromptPresetService: layered prompt built', [
                'layers' => $layerNames,
                'tenant_id' => $context['tenant_id'] ?? null,
                'context_keys' => array_keys($context),
                'total_chars' => mb_strlen($merged),
            ]);

            return $merged;
            
        } catch (\Throwable $e) {
            Log::warning('Failed to get layered prompt preset', [
                'error' => $e->getMessage(),
                'context' => $context,
            ]);
            return null;
        }
    }

    /**
     * Очистити кеш пресетів.
     * 
     * @param int|null $tenantId — конкретний тенант або null для всіх
     */
    public function clearCache(?int $tenantId = null): void
    {
        if ($tenantId) {
            Cache::forget(self::CACHE_KEY_PREFIX . ':' . $tenantId);
        } else {
            // Clear known tenant caches + global
            Cache::forget(self::CACHE_KEY_PREFIX . ':global');
            // Also try to clear with current tenant context
            $currentTenantId = $this->resolveTenantId();
            if ($currentTenantId) {
                Cache::forget(self::CACHE_KEY_PREFIX . ':' . $currentTenantId);
            }
        }
    }

    /**
     * Отримати автогенерований промпт зі StoreContext.
     * 
     * Використовується коли немає відповідного PromptPreset.
     */
    public function getAutoGeneratedPrompt(?int $widgetSettingsId = null): ?string
    {
        try {
            $context = StoreContext::where('widget_settings_id', $widgetSettingsId)
                ->orWhereNull('widget_settings_id')
                ->orderByDesc('updated_at')
                ->first();

            if (!$context || !$context->generated_prompt) {
                return null;
            }

            Log::info('Using auto-generated prompt from StoreContext', [
                'context_id' => $context->id,
                'store_type' => $context->store_type,
                'prompt_version' => $context->prompt_version,
            ]);

            return $context->generated_prompt;

        } catch (\Throwable $e) {
            Log::warning('Failed to get auto-generated prompt', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Отримати найкращий промпт для контексту.
     * 
     * Пріоритет:
     * 1. Layered PromptPresets (base + overlays)
     * 2. Auto-generated з StoreContext
     * 3. null (агент використає дефолтний)
     */
    public function getBestPromptForContext(array $context, array $values = [], ?int $widgetSettingsId = null): ?string
    {
        // 1. Try layered presets first
        $manualPrompt = $this->getSystemPromptForContext($context, $values);
        if ($manualPrompt) {
            return $manualPrompt;
        }

        // 2. Try auto-generated from StoreContext
        $autoPrompt = $this->getAutoGeneratedPrompt($widgetSettingsId);
        if ($autoPrompt) {
            // Replace {{tone_section}} with actual tone
            if (isset($values['tone_section'])) {
                $autoPrompt = str_replace('{{tone_section}}', $values['tone_section'], $autoPrompt);
            }
            return $autoPrompt;
        }

        // 3. Return null - agent will use default
        return null;
    }

    /**
     * Resolve current tenant ID from TenantContext.
     */
    private function resolveTenantId(): ?int
    {
        try {
            return app(\App\Services\Tenant\TenantContext::class)->getTenantId();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
