<?php

namespace Database\Seeders;

use App\Models\ProactiveTriggerRule;
use Illuminate\Database\Seeder;

class ProactiveTriggerRulesSeeder extends Seeder
{
    public function run(): void
    {
        $rules = [
            // ========================================
            // EXIT-INTENT TRIGGERS
            // ========================================
            [
                'name' => 'Exit Intent - Product Page',
                'trigger_type' => ProactiveTriggerRule::TYPE_EXIT_INTENT,
                'is_enabled' => true,
                'priority' => 10, // Highest priority
                'conditions' => [
                    'page_types' => ['product'],
                    'min_time_on_page' => 5, // seconds
                ],
                'message' => 'Йдете? Допоможу підібрати товар за 30 секунд! 🎯',
                'button_text' => 'Підібрати',
                'icon' => '🎯',
                'action_type' => ProactiveTriggerRule::ACTION_OPEN_CHAT_WITH_CONTEXT,
                'action_config' => [
                    'initial_message' => 'Допоможіть підібрати цей товар',
                    'include_product_context' => true,
                ],
                'max_per_session' => 1,
                'max_per_day' => 2,
                'cooldown_minutes' => 60,
            ],
            [
                'name' => 'Exit Intent - Category Page',
                'trigger_type' => ProactiveTriggerRule::TYPE_EXIT_INTENT,
                'is_enabled' => true,
                'priority' => 15,
                'conditions' => [
                    'page_types' => ['category'],
                    'min_time_on_page' => 10,
                ],
                'message' => 'Не знайшли потрібне? Покажу хіти категорії! 🔥',
                'button_text' => 'Показати хіти',
                'icon' => '🔥',
                'action_type' => ProactiveTriggerRule::ACTION_OPEN_CHAT_WITH_CONTEXT,
                'action_config' => [
                    'initial_message' => 'Покажи хіти цієї категорії',
                    'include_category_context' => true,
                ],
                'max_per_session' => 1,
                'max_per_day' => 2,
                'cooldown_minutes' => 60,
            ],

            // ========================================
            // TIME-ON-PAGE TRIGGERS
            // ========================================
            [
                'name' => 'Long Time on Product Page',
                'trigger_type' => ProactiveTriggerRule::TYPE_TIME_ON_PAGE,
                'is_enabled' => true,
                'priority' => 20,
                'conditions' => [
                    'page_types' => ['product'],
                    'min_seconds' => 45, // Trigger after 45 seconds
                    'idle_seconds' => 15, // And 15 seconds of no activity
                ],
                'message' => 'Не впевнені з розміром? Підберу за 3 питання! 📏',
                'button_text' => 'Підібрати розмір',
                'icon' => '📏',
                'action_type' => ProactiveTriggerRule::ACTION_OPEN_CHAT_WITH_CONTEXT,
                'action_config' => [
                    'initial_message' => 'Допоможіть підібрати розмір',
                    'include_product_context' => true,
                ],
                'max_per_session' => 1,
                'max_per_day' => 3,
                'cooldown_minutes' => 30,
            ],
            [
                'name' => 'Browsing Category Long Time',
                'trigger_type' => ProactiveTriggerRule::TYPE_TIME_ON_PAGE,
                'is_enabled' => true,
                'priority' => 25,
                'conditions' => [
                    'page_types' => ['category'],
                    'min_seconds' => 60, // 1 minute in category
                    'min_products_viewed' => 3, // Viewed at least 3 products
                ],
                'message' => 'Показати найпопулярніші товари в цій категорії? ⭐',
                'button_text' => 'Показати топ',
                'icon' => '⭐',
                'action_type' => ProactiveTriggerRule::ACTION_OPEN_CHAT_WITH_CONTEXT,
                'action_config' => [
                    'initial_message' => 'Покажи топ товари в цій категорії',
                    'sort_by' => 'popularity',
                ],
                'max_per_session' => 1,
                'max_per_day' => 3,
                'cooldown_minutes' => 30,
            ],

            // ========================================
            // UTM CAMPAIGN TRIGGERS - GOOGLE ADS
            // ========================================
            [
                'name' => 'Google Ads - CPC',
                'trigger_type' => ProactiveTriggerRule::TYPE_UTM_CAMPAIGN,
                'is_enabled' => true,
                'priority' => 30,
                'conditions' => [
                    'utm_source' => 'google',
                    'utm_medium' => 'cpc',
                    'delay_seconds' => 10, // Show after 10 seconds
                ],
                'message' => '👋 Вітаємо! Є питання по товару? Консультант онлайн!',
                'button_text' => 'Запитати',
                'icon' => '👋',
                'action_type' => ProactiveTriggerRule::ACTION_OPEN_CHAT,
                'action_config' => [
                    'track_source' => 'google_cpc',
                ],
                'max_per_session' => 1,
                'max_per_day' => 2,
                'cooldown_minutes' => 120,
            ],
            [
                'name' => 'Google Ads - Shopping',
                'trigger_type' => ProactiveTriggerRule::TYPE_UTM_CAMPAIGN,
                'is_enabled' => true,
                'priority' => 31,
                'conditions' => [
                    'utm_source' => 'google',
                    'utm_medium' => 'shopping',
                    'delay_seconds' => 15,
                ],
                'message' => '🛒 Прийшли з Google Shopping? Допоможу з вибором!',
                'button_text' => 'Отримати допомогу',
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

            // ========================================
            // UTM CAMPAIGN TRIGGERS - META (Facebook/Instagram)
            // ========================================
            [
                'name' => 'Facebook Ads',
                'trigger_type' => ProactiveTriggerRule::TYPE_UTM_CAMPAIGN,
                'is_enabled' => true,
                'priority' => 35,
                'conditions' => [
                    'utm_source' => 'facebook',
                    'delay_seconds' => 12,
                ],
                'message' => '📱 Вітаємо з Facebook! Покажу найкращі пропозиції?',
                'button_text' => 'Показати',
                'icon' => '📱',
                'action_type' => ProactiveTriggerRule::ACTION_OPEN_CHAT,
                'action_config' => [
                    'track_source' => 'facebook',
                ],
                'max_per_session' => 1,
                'max_per_day' => 2,
                'cooldown_minutes' => 120,
            ],
            [
                'name' => 'Instagram Ads',
                'trigger_type' => ProactiveTriggerRule::TYPE_UTM_CAMPAIGN,
                'is_enabled' => true,
                'priority' => 36,
                'conditions' => [
                    'utm_source' => 'instagram',
                    'delay_seconds' => 12,
                ],
                'message' => '📸 Раді бачити з Instagram! Допомогти з вибором?',
                'button_text' => 'Так, допоможіть',
                'icon' => '📸',
                'action_type' => ProactiveTriggerRule::ACTION_OPEN_CHAT,
                'action_config' => [
                    'track_source' => 'instagram',
                ],
                'max_per_session' => 1,
                'max_per_day' => 2,
                'cooldown_minutes' => 120,
            ],
            [
                'name' => 'Meta Ads (fb/ig combined)',
                'trigger_type' => ProactiveTriggerRule::TYPE_UTM_CAMPAIGN,
                'is_enabled' => true,
                'priority' => 37,
                'conditions' => [
                    'utm_source' => 'meta',
                    'delay_seconds' => 12,
                ],
                'message' => '👋 Привіт! Допомогти підібрати товар?',
                'button_text' => 'Підібрати',
                'icon' => '👋',
                'action_type' => ProactiveTriggerRule::ACTION_OPEN_CHAT,
                'action_config' => [
                    'track_source' => 'meta',
                ],
                'max_per_session' => 1,
                'max_per_day' => 2,
                'cooldown_minutes' => 120,
            ],

            // ========================================
            // UTM CAMPAIGN TRIGGERS - OTHER SOURCES
            // ========================================
            [
                'name' => 'TikTok Ads',
                'trigger_type' => ProactiveTriggerRule::TYPE_UTM_CAMPAIGN,
                'is_enabled' => true,
                'priority' => 40,
                'conditions' => [
                    'utm_source' => 'tiktok',
                    'delay_seconds' => 8, // Shorter for TikTok users
                ],
                'message' => '🎵 З TikTok? Покажу хіти, які зараз в тренді!',
                'button_text' => 'Показати тренди',
                'icon' => '🎵',
                'action_type' => ProactiveTriggerRule::ACTION_OPEN_CHAT_WITH_CONTEXT,
                'action_config' => [
                    'track_source' => 'tiktok',
                    'initial_message' => 'Покажи популярні товари',
                    'sort_by' => 'popularity',
                ],
                'max_per_session' => 1,
                'max_per_day' => 2,
                'cooldown_minutes' => 120,
            ],
            [
                'name' => 'Email Campaign',
                'trigger_type' => ProactiveTriggerRule::TYPE_UTM_CAMPAIGN,
                'is_enabled' => true,
                'priority' => 45,
                'conditions' => [
                    'utm_medium' => 'email',
                    'delay_seconds' => 15,
                ],
                'message' => '📧 Дякуємо що відкрили лист! Є питання по акції?',
                'button_text' => 'Запитати',
                'icon' => '📧',
                'action_type' => ProactiveTriggerRule::ACTION_OPEN_CHAT,
                'action_config' => [
                    'track_source' => 'email',
                ],
                'max_per_session' => 1,
                'max_per_day' => 2,
                'cooldown_minutes' => 120,
            ],
            [
                'name' => 'Telegram Ads',
                'trigger_type' => ProactiveTriggerRule::TYPE_UTM_CAMPAIGN,
                'is_enabled' => true,
                'priority' => 46,
                'conditions' => [
                    'utm_source' => 'telegram',
                    'delay_seconds' => 10,
                ],
                'message' => '✈️ Привіт з Telegram! Допомогти з вибором?',
                'button_text' => 'Допомогти',
                'icon' => '✈️',
                'action_type' => ProactiveTriggerRule::ACTION_OPEN_CHAT,
                'action_config' => [
                    'track_source' => 'telegram',
                ],
                'max_per_session' => 1,
                'max_per_day' => 2,
                'cooldown_minutes' => 120,
            ],

            // ========================================
            // SEASONAL/PROMOTIONAL UTM CAMPAIGNS
            // ========================================
            [
                'name' => 'Black Friday Campaign',
                'trigger_type' => ProactiveTriggerRule::TYPE_UTM_CAMPAIGN,
                'is_enabled' => false, // Enable during Black Friday
                'priority' => 25,
                'conditions' => [
                    'utm_campaign' => 'black_friday',
                    'delay_seconds' => 8,
                ],
                'message' => '🖤 Black Friday! Знижки до -50%! Показати найкращі пропозиції?',
                'button_text' => 'Показати знижки',
                'icon' => '🖤',
                'action_type' => ProactiveTriggerRule::ACTION_OPEN_CHAT_WITH_CONTEXT,
                'action_config' => [
                    'track_source' => 'black_friday',
                    'initial_message' => 'Покажи товари зі знижками Black Friday',
                ],
                'max_per_session' => 1,
                'max_per_day' => 3,
                'cooldown_minutes' => 60,
            ],
            [
                'name' => 'Winter Sale Campaign',
                'trigger_type' => ProactiveTriggerRule::TYPE_UTM_CAMPAIGN,
                'is_enabled' => true,
                'priority' => 28,
                'conditions' => [
                    'utm_campaign' => 'winter',
                    'delay_seconds' => 10,
                ],
                'message' => '❄️ Зимовий розпродаж! Покажу хіти сезону?',
                'button_text' => 'Показати',
                'icon' => '❄️',
                'action_type' => ProactiveTriggerRule::ACTION_OPEN_CHAT_WITH_CONTEXT,
                'action_config' => [
                    'track_source' => 'winter_sale',
                    'initial_message' => 'Що беруть зимою?',
                    'sort_by' => 'popularity',
                ],
                'max_per_session' => 1,
                'max_per_day' => 3,
                'cooldown_minutes' => 60,
            ],

            // ========================================
            // PDP NO VARIANT TRIGGER
            // ========================================
            [
                'name' => 'PDP - No Size Selected',
                'trigger_type' => ProactiveTriggerRule::TYPE_PDP_NO_VARIANT,
                'is_enabled' => true,
                'priority' => 18,
                'conditions' => [
                    'min_time_without_selection' => 30, // 30 seconds without selecting size
                    'has_variants' => true,
                ],
                'message' => 'Не впевнені з розміром? Підберу за вашими параметрами! 📐',
                'button_text' => 'Підібрати розмір',
                'icon' => '📐',
                'action_type' => ProactiveTriggerRule::ACTION_OPEN_CHAT_WITH_CONTEXT,
                'action_config' => [
                    'initial_message' => 'Допоможіть підібрати розмір для цього товару',
                    'include_product_context' => true,
                    'quiz_mode' => true,
                ],
                'max_per_session' => 1,
                'max_per_day' => 3,
                'cooldown_minutes' => 30,
            ],

            // ========================================
            // RETURNING VISITOR TRIGGER
            // ========================================
            [
                'name' => 'Returning Visitor',
                'trigger_type' => ProactiveTriggerRule::TYPE_RETURNING_VISITOR,
                'is_enabled' => true,
                'priority' => 50, // Lower priority
                'conditions' => [
                    'min_hours_since_last_visit' => 24, // At least 24 hours
                    'same_category' => true, // Returned to same category
                ],
                'message' => 'Раді бачити знову! Показати товари з минулого візиту та новинки? 👀',
                'button_text' => 'Показати',
                'icon' => '👀',
                'action_type' => ProactiveTriggerRule::ACTION_OPEN_CHAT_WITH_CONTEXT,
                'action_config' => [
                    'show_recently_viewed' => true,
                    'show_new_in_category' => true,
                ],
                'max_per_session' => 1,
                'max_per_day' => 1,
                'cooldown_minutes' => 240, // 4 hours
            ],
        ];

        foreach ($rules as $rule) {
            ProactiveTriggerRule::updateOrCreate(
                ['name' => $rule['name']],
                $rule
            );
        }

        $this->command->info('Created ' . count($rules) . ' proactive trigger rules');
    }
}
