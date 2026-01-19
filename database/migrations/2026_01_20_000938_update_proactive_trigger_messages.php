<?php

use App\Models\ProactiveTriggerRule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Update proactive trigger messages with better marketing copy
     * and personalization variables.
     */
    public function up(): void
    {
        // Skip if table doesn't exist
        if (!DB::getSchemaBuilder()->hasTable('proactive_trigger_rules')) {
            return;
        }

        $updates = $this->getMessageUpdates();

        foreach ($updates as $update) {
            ProactiveTriggerRule::where('id', $update['id'])
                ->update([
                    'message' => $update['message'],
                    'button_text' => $update['button_text'],
                    'icon' => $update['icon'],
                ]);
        }
    }

    /**
     * Get the updated messages for each trigger.
     */
    protected function getMessageUpdates(): array
    {
        return [
            // Exit Intent - Product Page (ID: 1)
            [
                'id' => 1,
                'message' => "⏳ Зачекайте!\n\nЦей товар переглядають ще 3 покупці. Допоможу швидко оформити замовлення!",
                'button_text' => 'Допоможіть обрати',
                'icon' => '⏳',
            ],

            // Exit Intent - Category Page (ID: 2)
            [
                'id' => 2,
                'message' => "🔍 Не знайшли потрібне в «{{category}}»?\n\nПокажу ТОП-5 товарів за вашим запитом за 10 секунд!",
                'button_text' => 'Показати хіти',
                'icon' => '🔥',
            ],

            // Time on Product Page (ID: 3)
            [
                'id' => 3,
                'message' => "📏 Обираєте «{{product}}»?\n\nДопоможу підібрати розмір за вашими параметрами — це займе 30 секунд!",
                'button_text' => 'Підібрати розмір',
                'icon' => '📏',
            ],

            // Time on Category Page (ID: 4)
            [
                'id' => 4,
                'message' => "⭐ Бачу, шукаєте в «{{category}}»\n\nПокажу бестселери, які замовляють найчастіше?",
                'button_text' => 'Показати ТОП',
                'icon' => '⭐',
            ],

            // Google CPC (ID: 5)
            [
                'id' => 5,
                'message' => "👋 Вітаємо з Google!\n\nМаєте питання по «{{product}}»? Відповім миттєво!",
                'button_text' => 'Запитати',
                'icon' => '💬',
            ],

            // Google Shopping (ID: 6)
            [
                'id' => 6,
                'message' => "🛒 Прийшли з Google Shopping?\n\nДопоможу порівняти характеристики та обрати найкращий варіант!",
                'button_text' => 'Порівняти',
                'icon' => '🛒',
            ],

            // Facebook (ID: 7)
            [
                'id' => 7,
                'message' => "📱 Вітаємо з Facebook!\n\n🔥 Покажу гарячі новинки, які зараз у тренді?",
                'button_text' => 'Показати тренди',
                'icon' => '🔥',
            ],

            // Instagram (ID: 8)
            [
                'id' => 8,
                'message' => "📸 Раді бачити з Instagram!\n\nПокажу товари, які найчастіше постять наші покупці?",
                'button_text' => 'Показати хіти',
                'icon' => '📸',
            ],

            // Meta (ID: 9)
            [
                'id' => 9,
                'message' => "👋 Привіт!\n\nДопоможу підібрати ідеальний товар під ваші потреби. Це займе хвилину!",
                'button_text' => 'Почати підбір',
                'icon' => '🎯',
            ],

            // TikTok (ID: 10)
            [
                'id' => 10,
                'message' => "🎵 З TikTok? Круто!\n\n🔥 Покажу товари, які зараз вірусяться — ті самі, з відео!",
                'button_text' => 'Показати вірусні',
                'icon' => '🎵',
            ],

            // Email (ID: 11)
            [
                'id' => 11,
                'message' => "📧 Дякуємо, що відкрили лист!\n\nЗалишились питання по акції? Відповім за 30 секунд!",
                'button_text' => 'Уточнити деталі',
                'icon' => '💰',
            ],

            // Telegram (ID: 12)
            [
                'id' => 12,
                'message' => "✈️ Привіт з Telegram-каналу!\n\nПокажу ексклюзивні пропозиції тільки для підписників?",
                'button_text' => 'Показати ексклюзив',
                'icon' => '🎁',
            ],

            // Winter Sale (ID: 14)
            [
                'id' => 14,
                'message' => "❄️ Зимовий розпродаж!\n\n🔥 До -50% на утеплений одяг. Покажу найкращі знижки?",
                'button_text' => 'Показати знижки',
                'icon' => '❄️',
            ],

            // PDP No Variant (ID: 15)
            [
                'id' => 15,
                'message' => "📐 Ще не обрали розмір?\n\nРозкажіть зріст та вагу — підберу ідеальний за 30 секунд!",
                'button_text' => 'Підібрати',
                'icon' => '📐',
            ],

            // Returning Visitor (ID: 16)
            [
                'id' => 16,
                'message' => "👋 З поверненням!\n\nПокажу що нового з'явилось з вашого минулого візиту?",
                'button_text' => 'Показати новинки',
                'icon' => '✨',
            ],
        ];
    }

    /**
     * Reverse the migrations (optional - just keep old messages).
     */
    public function down(): void
    {
        // Messages before migration are not stored, so we can't revert
    }
};
