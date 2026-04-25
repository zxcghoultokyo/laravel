<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TENANT_ID = 20;

    private const SLUG = 'bavkatoys-gift-selection';

    public function up(): void
    {
        $now = now();

        $prompt = <<<'PROMPT'
🎁 ПРАВИЛА ВИБОРУ ПОДАРУНКА (Bavka Toys, пріоритет над загальним пошуком):

КОЛИ СПРАЦЬОВУЄ: користувач каже «подарунок», «на рочок», «на день народження»,
«дарувати», «що подарувати», «present», «gift», або вказує вік дитини у контексті
вибору презенту.

✅ ЩО ПРОПОНУВАТИ В ПЕРШУ ЧЕРГУ:
1. ГОТОВІ НАБОРИ / КОМПЛЕКТИ — товари зі словами «набір», «комплект»,
   «подарунковий», «in a box». Це найкращий подарунок: вже скомпоновано,
   гарно запаковано, виглядає як «справжній» презент.
2. Універсальні розвиваючі іграшки відповідного віку.
3. Якщо у видачі є і «Набір музичних інструментів», і його окремі елементи
   (клавеси, рубель, маракаси, ритмічні палички, сопілки, бубонці) —
   ПРОПОНУЙ ТІЛЬКИ НАБІР. НЕ перелічуй компоненти набору окремими картками.

❌ ЩО НЕ ПРОПОНУВАТИ ЯК ПОДАРУНОК (навіть якщо Meili повернув):
- Сертифікати — лише якщо клієнт явно попросив сертифікат.
- Подарункові пакети, упаковка, коробки — це додаток на касі, не подарунок.
- Електронні зошити / PDF / навчальні посібники — їх не дарують.
- Утилітарний одяг: фартухи, нарукавники, слинявчики — це для побуту.
- Підвіски на ліжечко, мобілі, тренажери-перекладини — товари батькам, не подарунок.
- Коробочка постійності — актуальна тільки до 12 міс, на 1 рік уже пізно.
- Товари «новонародженим / 0–6 міс / Рання Пташка» — для запиту 1+ рік не підходять.
- Окремі монтесорі-картки, дрібні аксесуари без коробки.

🧒 ВРАХОВУЙ ВІК З ІСТОРІЇ РОЗМОВИ:
Якщо в попередніх повідомленнях клієнт казав «на рочок», «1 рік», «дитинці 2 роки»
тощо — застосовуй цей вік до ВСІХ наступних запитів («покажи ще», «комплекти»,
«а що популярне»), доки користувач явно не змінив вік.

📦 ФОРМАТ ВІДПОВІДІ ПРИ ПОДАРУНКОВОМУ ЗАПИТІ:
- 1 коротке речення: «Ось ідеальні подарунки на рік:» (підлаштуй під вік).
- Максимум 3 товари (системне правило).
- НЕ повторюй одне й те саме між повідомленнями — використовуй різні набори.
PROMPT;

        DB::table('prompt_presets')->updateOrInsert(
            ['slug' => self::SLUG],
            [
                'tenant_id' => self::TENANT_ID,
                'name' => 'Bavkatoys — Gift Selection',
                'description' => 'Overlay: пріоритезує готові набори/комплекти для подарункових запитів і блокує нерелевантні SKU (фартух, сертифікат, упаковка, новонароджені).',
                'system_prompt' => $prompt,
                'categories' => null,
                'language' => null,
                'tone' => null,
                'campaign' => null,
                'store_type' => null,
                'variables' => null,
                'is_active' => true,
                'is_default' => false,
                'priority' => 95,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        // Drop the per-tenant prompt cache so the overlay is picked up immediately.
        try {
            \Illuminate\Support\Facades\Cache::forget('prompt_presets_active:'.self::TENANT_ID);
        } catch (\Throwable $e) {
            // Ignore — cache may not be available during migration.
        }
    }

    public function down(): void
    {
        DB::table('prompt_presets')->where('slug', self::SLUG)->delete();

        try {
            \Illuminate\Support\Facades\Cache::forget('prompt_presets_active:'.self::TENANT_ID);
        } catch (\Throwable $e) {
            // Ignore.
        }
    }
};
