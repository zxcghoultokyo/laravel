<?php

namespace App\Services\Tenant;

use App\Models\ProactiveTriggerRule;
use App\Models\Tenant;

class DefaultTriggerService
{
    /**
     * Create default proactive trigger rules for a new tenant.
     */
    public function createDefaultTriggers(Tenant $tenant): void
    {
        $defaults = $this->getDefaultTriggers($tenant);

        foreach ($defaults as $triggerData) {
            ProactiveTriggerRule::create([
                'tenant_id' => $tenant->id,
                ...$triggerData,
            ]);
        }
    }

    /**
     * Get array of default trigger configurations.
     */
    protected function getDefaultTriggers(Tenant $tenant): array
    {
        return [
            // 1. Exit Intent - high conversion potential
            [
                'name' => 'Exit Intent - Допомога',
                'trigger_type' => ProactiveTriggerRule::TYPE_EXIT_INTENT,
                'is_enabled' => true,
                'priority' => 10,
                'conditions' => [
                    'page_type' => 'any',
                    'min_time_on_page' => 5,
                ],
                'message' => "Зачекайте! 👋\n\nМожливо, вам потрібна допомога з вибором? Я можу підказати найкращий варіант для вас.",
                'button_text' => 'Так, допоможіть',
                'icon' => '👋',
                'action_type' => ProactiveTriggerRule::ACTION_OPEN_CHAT,
                'action_config' => [],
                'max_per_session' => 1,
                'max_per_day' => 2,
                'cooldown_minutes' => 60,
            ],

            // 2. Time on page - engaged visitors
            [
                'name' => 'Довго на сторінці - Питання?',
                'trigger_type' => ProactiveTriggerRule::TYPE_TIME_ON_PAGE,
                'is_enabled' => true,
                'priority' => 20,
                'conditions' => [
                    'time_seconds' => 45,
                    'page_type' => 'product',
                ],
                'message' => "Бачу, що ви розглядаєте цей товар. 🤔\n\nМаєте питання щодо розмірів, матеріалів чи доставки?",
                'button_text' => 'Запитати',
                'icon' => '💬',
                'action_type' => ProactiveTriggerRule::ACTION_OPEN_CHAT_WITH_CONTEXT,
                'action_config' => [
                    'context' => 'product_page',
                ],
                'max_per_session' => 1,
                'max_per_day' => 3,
                'cooldown_minutes' => 30,
            ],

            // 3. PDP without variant selection
            [
                'name' => 'Сторінка товару без вибору',
                'trigger_type' => ProactiveTriggerRule::TYPE_PDP_NO_VARIANT,
                'is_enabled' => true,
                'priority' => 15,
                'conditions' => [
                    'variant_timeout' => 15,
                ],
                'message' => "Потрібна допомога з вибором розміру чи кольору? 📏\n\nМожу порадити, який варіант підійде саме вам!",
                'button_text' => 'Підібрати',
                'icon' => '📏',
                'action_type' => ProactiveTriggerRule::ACTION_OPEN_CHAT_WITH_CONTEXT,
                'action_config' => [
                    'context' => 'variant_help',
                ],
                'max_per_session' => 1,
                'max_per_day' => 3,
                'cooldown_minutes' => 20,
            ],

            // 4. Returning visitor
            [
                'name' => 'Привіт знову!',
                'trigger_type' => ProactiveTriggerRule::TYPE_RETURNING_VISITOR,
                'is_enabled' => true,
                'priority' => 5,
                'conditions' => [
                    'min_visits' => 2,
                    'delay_seconds' => 10,
                ],
                'message' => "З поверненням! 👋\n\nРаді бачити вас знову. Чи можу допомогти знайти те, що ви шукали минулого разу?",
                'button_text' => 'Так, допоможіть',
                'icon' => '🎉',
                'action_type' => ProactiveTriggerRule::ACTION_OPEN_CHAT,
                'action_config' => [],
                'max_per_session' => 1,
                'max_per_day' => 1,
                'cooldown_minutes' => 120,
            ],

            // 5. UTM Campaign welcome (disabled by default - needs campaign setup)
            [
                'name' => 'Кампанія - Акційна пропозиція',
                'trigger_type' => ProactiveTriggerRule::TYPE_UTM_CAMPAIGN,
                'is_enabled' => false, // Disabled until campaign is set up
                'priority' => 1,
                'conditions' => [
                    'utm_source' => '',
                    'utm_medium' => '',
                    'utm_campaign' => 'promo',
                    'delay_seconds' => 5,
                ],
                'message' => "Вітаємо! 🎁\n\nВи прийшли за акційною пропозицією? Допоможу знайти найкращі знижки!",
                'button_text' => 'Показати акції',
                'icon' => '🎁',
                'action_type' => ProactiveTriggerRule::ACTION_OPEN_CHAT_WITH_CONTEXT,
                'action_config' => [
                    'context' => 'promo_campaign',
                ],
                'max_per_session' => 1,
                'max_per_day' => 3,
                'cooldown_minutes' => 30,
            ],
        ];
    }

    /**
     * Check if tenant has any triggers.
     */
    public function hasTriggers(Tenant $tenant): bool
    {
        return ProactiveTriggerRule::where('tenant_id', $tenant->id)->exists();
    }

    /**
     * Seed default triggers for tenants that don't have any.
     * Useful for migration of existing tenants.
     */
    public function seedMissingTriggers(): int
    {
        $count = 0;
        $tenants = Tenant::whereDoesntHave('proactiveTriggerRules')->get();

        foreach ($tenants as $tenant) {
            $this->createDefaultTriggers($tenant);
            $count++;
        }

        return $count;
    }
}
