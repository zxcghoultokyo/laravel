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
     * Optimized for high conversion with personalization variables.
     */
    protected function getDefaultTriggers(Tenant $tenant): array
    {
        return [
            // ========================================
            // EXIT INTENT TRIGGERS (highest priority)
            // ========================================
            
            // 1. Exit Intent - Product Page (urgency + social proof)
            [
                'name' => 'Exit Intent - Товарна сторінка',
                'trigger_type' => ProactiveTriggerRule::TYPE_EXIT_INTENT,
                'is_enabled' => true,
                'priority' => 10,
                'conditions' => [
                    'page_type' => 'product',
                    'min_time_on_site' => 5,
                ],
                'message' => "⏳ Зачекайте!\n\nЦей товар переглядають ще 3 покупці. Допоможу швидко оформити замовлення!",
                'button_text' => 'Допоможіть обрати',
                'icon' => '⏳',
                'action_type' => ProactiveTriggerRule::ACTION_OPEN_CHAT_WITH_CONTEXT,
                'action_config' => ['include_product_context' => true],
                'max_per_session' => 1,
                'max_per_day' => 2,
                'cooldown_minutes' => 60,
            ],

            // 2. Exit Intent - Category Page (help + category context)
            [
                'name' => 'Exit Intent - Категорія',
                'trigger_type' => ProactiveTriggerRule::TYPE_EXIT_INTENT,
                'is_enabled' => true,
                'priority' => 15,
                'conditions' => [
                    'page_types' => ['category'],
                    'min_time_on_page' => 10,
                ],
                'message' => "🔍 Не знайшли потрібне в «{{category}}»?\n\nПокажу ТОП-5 товарів за вашим запитом за 10 секунд!",
                'button_text' => 'Показати хіти',
                'icon' => '🔥',
                'action_type' => ProactiveTriggerRule::ACTION_OPEN_CHAT_WITH_CONTEXT,
                'action_config' => [
                    'include_category_context' => true,
                    'initial_message' => 'Покажи хіти цієї категорії',
                ],
                'max_per_session' => 1,
                'max_per_day' => 2,
                'cooldown_minutes' => 60,
            ],

            // ========================================
            // TIME ON PAGE TRIGGERS (engaged visitors)
            // ========================================

            // 3. Time on Product Page - Size help
            [
                'name' => 'Довго на товарі - Допомога з розміром',
                'trigger_type' => ProactiveTriggerRule::TYPE_TIME_ON_PAGE,
                'is_enabled' => true,
                'priority' => 20,
                'conditions' => [
                    'page_types' => ['product'],
                    'min_seconds' => 45,
                    'idle_seconds' => 15,
                ],
                'message' => "📏 Обираєте «{{product}}»?\n\nДопоможу підібрати розмір за вашими параметрами — це займе 30 секунд!",
                'button_text' => 'Підібрати розмір',
                'icon' => '📏',
                'action_type' => ProactiveTriggerRule::ACTION_OPEN_CHAT_WITH_CONTEXT,
                'action_config' => [
                    'include_product_context' => true,
                    'initial_message' => 'Допоможіть підібрати розмір',
                ],
                'max_per_session' => 1,
                'max_per_day' => 3,
                'cooldown_minutes' => 30,
            ],

            // 4. Time on Category Page - Show bestsellers
            [
                'name' => 'Довго в категорії - Бестселери',
                'trigger_type' => ProactiveTriggerRule::TYPE_TIME_ON_PAGE,
                'is_enabled' => true,
                'priority' => 25,
                'conditions' => [
                    'page_types' => ['category'],
                    'min_seconds' => 60,
                    'min_products_viewed' => 3,
                ],
                'message' => "⭐ Бачу, шукаєте в «{{category}}»\n\nПокажу бестселери, які замовляють найчастіше?",
                'button_text' => 'Показати ТОП',
                'icon' => '⭐',
                'action_type' => ProactiveTriggerRule::ACTION_OPEN_CHAT_WITH_CONTEXT,
                'action_config' => [
                    'sort_by' => 'popularity',
                    'initial_message' => 'Покажи топ товари в цій категорії',
                ],
                'max_per_session' => 1,
                'max_per_day' => 3,
                'cooldown_minutes' => 30,
            ],

            // ========================================
            // PDP NO VARIANT (sizing help)
            // ========================================

            // 5. PDP no variant - Quick size help
            [
                'name' => 'Не обрано розмір',
                'trigger_type' => ProactiveTriggerRule::TYPE_PDP_NO_VARIANT,
                'is_enabled' => true,
                'priority' => 18,
                'conditions' => [
                    'has_variants' => true,
                    'min_time_without_selection' => 30,
                ],
                'message' => "📐 Ще не обрали розмір?\n\nРозкажіть зріст та вагу — підберу ідеальний за 30 секунд!",
                'button_text' => 'Підібрати',
                'icon' => '📐',
                'action_type' => ProactiveTriggerRule::ACTION_OPEN_CHAT_WITH_CONTEXT,
                'action_config' => [
                    'quiz_mode' => true,
                    'include_product_context' => true,
                    'initial_message' => 'Допоможіть підібрати розмір для цього товару',
                ],
                'max_per_session' => 1,
                'max_per_day' => 3,
                'cooldown_minutes' => 30,
            ],

            // ========================================
            // RETURNING VISITOR (re-engagement)
            // ========================================

            // 6. Returning visitor - What's new
            [
                'name' => 'Повернувся відвідувач',
                'trigger_type' => ProactiveTriggerRule::TYPE_RETURNING_VISITOR,
                'is_enabled' => true,
                'priority' => 50,
                'conditions' => [
                    'same_category' => true,
                    'min_hours_since_last_visit' => 24,
                ],
                'message' => "👋 З поверненням!\n\nПокажу що нового з'явилось з вашого минулого візиту?",
                'button_text' => 'Показати новинки',
                'icon' => '✨',
                'action_type' => ProactiveTriggerRule::ACTION_OPEN_CHAT_WITH_CONTEXT,
                'action_config' => [
                    'show_new_in_category' => true,
                    'show_recently_viewed' => true,
                ],
                'max_per_session' => 1,
                'max_per_day' => 1,
                'cooldown_minutes' => 240,
            ],

            // ========================================
            // UTM CAMPAIGN TRIGGERS (traffic source based)
            // ========================================

            // 7. Google CPC - Quick help
            [
                'name' => 'Google Ads CPC',
                'trigger_type' => ProactiveTriggerRule::TYPE_UTM_CAMPAIGN,
                'is_enabled' => true,
                'priority' => 30,
                'conditions' => [
                    'utm_medium' => 'cpc',
                    'utm_source' => 'google',
                    'delay_seconds' => 10,
                ],
                'message' => "👋 Вітаємо з Google!\n\nМаєте питання по «{{product}}»? Відповім миттєво!",
                'button_text' => 'Запитати',
                'icon' => '💬',
                'action_type' => ProactiveTriggerRule::ACTION_OPEN_CHAT,
                'action_config' => ['track_source' => 'google_cpc'],
                'max_per_session' => 1,
                'max_per_day' => 2,
                'cooldown_minutes' => 120,
            ],

            // 8. Google Shopping - Compare help
            [
                'name' => 'Google Shopping',
                'trigger_type' => ProactiveTriggerRule::TYPE_UTM_CAMPAIGN,
                'is_enabled' => true,
                'priority' => 31,
                'conditions' => [
                    'utm_medium' => 'shopping',
                    'utm_source' => 'google',
                    'delay_seconds' => 15,
                ],
                'message' => "🛒 Прийшли з Google Shopping?\n\nДопоможу порівняти характеристики та обрати найкращий варіант!",
                'button_text' => 'Порівняти',
                'icon' => '🛒',
                'action_type' => ProactiveTriggerRule::ACTION_OPEN_CHAT_WITH_CONTEXT,
                'action_config' => [
                    'track_source' => 'google_shopping',
                    'include_product_context' => true,
                ],
                'max_per_session' => 1,
                'max_per_day' => 2,
                'cooldown_minutes' => 120,
            ],

            // 9. Facebook - Show trends
            [
                'name' => 'Facebook Ads',
                'trigger_type' => ProactiveTriggerRule::TYPE_UTM_CAMPAIGN,
                'is_enabled' => true,
                'priority' => 35,
                'conditions' => [
                    'utm_source' => 'facebook',
                    'delay_seconds' => 12,
                ],
                'message' => "📱 Вітаємо з Facebook!\n\n🔥 Покажу гарячі новинки, які зараз у тренді?",
                'button_text' => 'Показати тренди',
                'icon' => '🔥',
                'action_type' => ProactiveTriggerRule::ACTION_OPEN_CHAT,
                'action_config' => ['track_source' => 'facebook'],
                'max_per_session' => 1,
                'max_per_day' => 2,
                'cooldown_minutes' => 120,
            ],

            // 10. Instagram - Show popular
            [
                'name' => 'Instagram Ads',
                'trigger_type' => ProactiveTriggerRule::TYPE_UTM_CAMPAIGN,
                'is_enabled' => true,
                'priority' => 36,
                'conditions' => [
                    'utm_source' => 'instagram',
                    'delay_seconds' => 12,
                ],
                'message' => "📸 Раді бачити з Instagram!\n\nПокажу товари, які найчастіше постять наші покупці?",
                'button_text' => 'Показати хіти',
                'icon' => '📸',
                'action_type' => ProactiveTriggerRule::ACTION_OPEN_CHAT,
                'action_config' => ['track_source' => 'instagram'],
                'max_per_session' => 1,
                'max_per_day' => 2,
                'cooldown_minutes' => 120,
            ],

            // 11. TikTok - Viral products
            [
                'name' => 'TikTok Ads',
                'trigger_type' => ProactiveTriggerRule::TYPE_UTM_CAMPAIGN,
                'is_enabled' => true,
                'priority' => 40,
                'conditions' => [
                    'utm_source' => 'tiktok',
                    'delay_seconds' => 8,
                ],
                'message' => "🎵 З TikTok? Круто!\n\n🔥 Покажу товари, які зараз вірусяться — ті самі, з відео!",
                'button_text' => 'Показати вірусні',
                'icon' => '🎵',
                'action_type' => ProactiveTriggerRule::ACTION_OPEN_CHAT_WITH_CONTEXT,
                'action_config' => [
                    'sort_by' => 'popularity',
                    'track_source' => 'tiktok',
                    'initial_message' => 'Покажи популярні товари',
                ],
                'max_per_session' => 1,
                'max_per_day' => 2,
                'cooldown_minutes' => 120,
            ],

            // 12. Email - Promo help
            [
                'name' => 'Email розсилка',
                'trigger_type' => ProactiveTriggerRule::TYPE_UTM_CAMPAIGN,
                'is_enabled' => true,
                'priority' => 45,
                'conditions' => [
                    'utm_medium' => 'email',
                    'delay_seconds' => 15,
                ],
                'message' => "📧 Дякуємо, що відкрили лист!\n\nЗалишились питання по акції? Відповім за 30 секунд!",
                'button_text' => 'Уточнити деталі',
                'icon' => '💰',
                'action_type' => ProactiveTriggerRule::ACTION_OPEN_CHAT,
                'action_config' => ['track_source' => 'email'],
                'max_per_session' => 1,
                'max_per_day' => 2,
                'cooldown_minutes' => 120,
            ],

            // 13. Telegram - Exclusive offers
            [
                'name' => 'Telegram канал',
                'trigger_type' => ProactiveTriggerRule::TYPE_UTM_CAMPAIGN,
                'is_enabled' => true,
                'priority' => 46,
                'conditions' => [
                    'utm_source' => 'telegram',
                    'delay_seconds' => 10,
                ],
                'message' => "✈️ Привіт з Telegram-каналу!\n\nПокажу ексклюзивні пропозиції тільки для підписників?",
                'button_text' => 'Показати ексклюзив',
                'icon' => '🎁',
                'action_type' => ProactiveTriggerRule::ACTION_OPEN_CHAT,
                'action_config' => ['track_source' => 'telegram'],
                'max_per_session' => 1,
                'max_per_day' => 2,
                'cooldown_minutes' => 120,
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
