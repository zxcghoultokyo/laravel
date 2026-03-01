# @znyzhka — TG Канал зі знижками | ТЗ-Промпт

Скопіюй все нижче в новий проект.

---

## ПРОМПТ

Створи Laravel 12 проект "Znyzhka" — автоматизований Telegram канал зі знижками для українського ринку з AI-генерацією контенту (тексти + зображення) та адмін-панеллю.

### Концепція

Повністю автоматизований TG канал @znyzhka який:
- Публікує 5-8 постів/день по розкладу з рандомізацією часу
- Кожен пост з AI-згенерованим текстом + зображенням
- Різні рубрики (знижка, битва цін, фейк чи реал, тощо)
- Голосування через TG реакції + inline кнопки
- Розіграші та промокоди для залучення
- Affiliate лінки (Admitad) у кожному пості
- Все керується через захищену веб-адмінку

Монетизація: affiliate маркетинг (2.5-8% від покупок через наші посилання).

---

## Технічний стек

- **Backend:** Laravel 12, PHP 8.3
- **Admin UI:** Filament 3 (Laravel admin panel, з коробки дає CRUD, auth, dashboard)
- **TG API:** Nutgram (nutgram/nutgram) — Laravel-native Telegram SDK
- **AI Тексти:** OpenAI API (GPT-4o-mini для генерації, GPT-4o для складних)
- **AI Зображення:** OpenAI DALL-E 3 API + Replicate API (Flux Schnell як fallback/альтернатива)
- **DB:** MySQL
- **Queue:** Redis + Laravel Queue
- **Scheduler:** Laravel Schedule + рандомізація
- **Cache:** Redis
- **Storage:** S3-сумісне сховище (або local для dev) для згенерованих зображень

---

## Архітектура проекту

```
app/
├── Console/Commands/
│   ├── PublishScheduledPosts.php    # Публікація запланованих постів
│   ├── GeneratePostContent.php     # AI генерація тексту + зображення
│   ├── ParseDeals.php              # Парсинг знижок з магазинів
│   ├── DrawRaffle.php              # Розіграш переможця лотереї
│   └── CleanupOldPosts.php         # Очистка старих даних
├── Enums/
│   ├── PostType.php                # Типи постів (рубрики)
│   ├── PostStatus.php              # draft, scheduled, published, failed
│   ├── DealCategory.php            # Категорії товарів
│   ├── StoreType.php               # Магазини (rozetka, comfy, allo...)
│   └── RaffleStatus.php            # Статуси розіграшів
├── Filament/
│   ├── Resources/
│   │   ├── PostResource.php        # CRUD постів
│   │   ├── DealResource.php        # CRUD знижок/товарів
│   │   ├── TemplateResource.php    # Шаблони постів
│   │   ├── RaffleResource.php      # Розіграші
│   │   └── SettingResource.php     # Налаштування
│   ├── Pages/
│   │   ├── Dashboard.php           # Головна: статистика, черга постів
│   │   ├── PostCalendar.php        # Календар запланованих постів
│   │   ├── GeneratePost.php        # Генерація поста через AI
│   │   └── ChannelStats.php        # Аналітика каналу
│   └── Widgets/
│       ├── UpcomingPostsWidget.php  # Наступні 5 постів
│       ├── StatsOverview.php        # Кліки, підписники, дохід
│       └── TopDealsWidget.php       # Найпопулярніші знижки
├── Jobs/
│   ├── GeneratePostTextJob.php      # AI генерація тексту
│   ├── GeneratePostImageJob.php     # AI генерація зображення
│   ├── PublishToTelegramJob.php     # Публікація в TG канал
│   ├── ParseStoreDealsjob.php       # Парсинг магазину
│   ├── TrackPostStatsJob.php        # Збір статистики поста
│   └── ProcessRaffleJob.php         # Обробка розіграшу
├── Models/
│   ├── Post.php                     # Пост в каналі
│   ├── Deal.php                     # Знижка/товар
│   ├── Template.php                 # Шаблон поста
│   ├── Raffle.php                   # Розіграш
│   ├── RaffleParticipant.php        # Учасник розіграшу
│   ├── PromoCode.php                # Промокод
│   ├── Setting.php                  # Глобальні налаштування
│   ├── PostStat.php                 # Статистика поста
│   └── User.php                     # Адмін (Filament auth)
├── Services/
│   ├── AI/
│   │   ├── PostTextGenerator.php    # Генерація тексту поста
│   │   ├── ImageGenerator.php       # Генерація зображення
│   │   ├── ImagePromptBuilder.php   # Побудова промпта для зображення
│   │   └── DealScorer.php           # AI оцінка якості знижки
│   ├── Telegram/
│   │   ├── ChannelPublisher.php     # Публікація в канал
│   │   ├── PostFormatter.php        # Форматування поста для TG
│   │   ├── ReactionTracker.php      # Трекінг реакцій
│   │   └── InlineKeyboardBuilder.php # Побудова кнопок
│   ├── Parsers/
│   │   ├── ParserInterface.php
│   │   ├── RozetkaParser.php
│   │   ├── ComfyParser.php
│   │   ├── AlloParser.php
│   │   └── AliExpressParser.php
│   ├── Affiliate/
│   │   ├── AdmitadService.php       # Генерація affiliate лінків
│   │   └── LinkWrapper.php          # Обгортка URL
│   └── Scheduling/
│       ├── PostScheduler.php        # Розподіл постів по часу з рандомом
│       └── RubricRotator.php        # Ротація рубрик протягом дня
└── Support/
    ├── PostTypeConfig.php           # Конфіг кожного типу поста
    └── TimeRandomizer.php           # Рандомізація часу публікації
```

---

## База даних

### Таблиця `posts` — головна

```php
Schema::create('posts', function (Blueprint $table) {
    $table->id();
    $table->string('type');                    // PostType enum: deal, battle, fake_or_real, anomaly, top3, digest, raffle, promo, lifehack
    $table->string('status')->default('draft'); // draft, generating, ready, scheduled, publishing, published, failed
    
    // Контент
    $table->string('title', 500);              // Заголовок
    $table->text('body');                      // Тіло поста (TG Markdown)
    $table->text('body_html')->nullable();     // HTML версія
    $table->string('image_path')->nullable();  // Шлях до згенерованого зображення
    $table->string('image_prompt')->nullable(); // Промпт яким генерили зображення
    $table->json('inline_keyboard')->nullable(); // Кнопки під постом (JSON)
    
    // Зв'язки
    $table->foreignId('deal_id')->nullable()->constrained();
    $table->foreignId('template_id')->nullable()->constrained();
    $table->foreignId('raffle_id')->nullable()->constrained('raffles');
    
    // Планування
    $table->timestamp('scheduled_at')->nullable();  // Коли опублікувати
    $table->timestamp('published_at')->nullable();
    $table->bigInteger('telegram_message_id')->nullable();
    
    // Статистика (оновлюється періодично)
    $table->integer('views')->default(0);
    $table->integer('reactions_fire')->default(0);   // 🔥
    $table->integer('reactions_poop')->default(0);   // 💩
    $table->integer('reactions_shock')->default(0);  // 😱
    $table->integer('reactions_heart')->default(0);  // ❤️
    $table->integer('clicks')->default(0);
    $table->integer('forwards')->default(0);         // Скільки разів переслали
    
    // Мета
    $table->json('meta')->nullable();          // Додаткові дані (порівняння цін тощо)
    $table->text('generation_log')->nullable(); // Лог AI генерації
    
    $table->timestamps();
    $table->softDeletes();
    
    $table->index('type');
    $table->index('status');
    $table->index('scheduled_at');
});
```

### Таблиця `deals` — знижки/товари

```php
Schema::create('deals', function (Blueprint $table) {
    $table->id();
    $table->string('store', 50);              // rozetka, comfy, allo, aliexpress
    $table->string('title', 500);
    $table->text('description')->nullable();
    $table->decimal('price', 10, 2);
    $table->decimal('old_price', 10, 2)->nullable();
    $table->integer('discount_percent')->nullable();
    $table->string('url', 1000);              // Оригінальне посилання
    $table->string('affiliate_url', 1000)->nullable();
    $table->string('image_url', 1000)->nullable();  // Оригінальне фото товару
    $table->string('brand', 100)->nullable();
    $table->string('category', 50);           // DealCategory enum
    $table->tinyInteger('ai_score')->nullable();     // 1-10
    $table->string('ai_verdict', 500)->nullable();   // Вердикт AI
    $table->string('promo_code', 100)->nullable();
    $table->boolean('is_used')->default(false);      // Чи використано в пості
    $table->json('price_history')->nullable();       // Історія цін [{date, price}]
    $table->json('competitors')->nullable();         // Ціни в інших магазинах
    $table->timestamp('expires_at')->nullable();
    $table->timestamps();
    
    $table->index(['store', 'category']);
    $table->index('ai_score');
    $table->index('is_used');
});
```

### Таблиця `templates` — шаблони постів

```php
Schema::create('templates', function (Blueprint $table) {
    $table->id();
    $table->string('name');                    // "Звичайна знижка", "Битва цін"
    $table->string('type');                    // PostType enum
    $table->text('text_prompt');               // Промпт для GPT генерації тексту
    $table->text('image_prompt_template');     // Шаблон промпта для зображення
    $table->text('example_output')->nullable(); // Приклад результату
    $table->json('variables');                 // Які змінні використовуються: [{name, description, required}]
    $table->json('inline_keyboard_template')->nullable(); // Шаблон кнопок
    $table->string('tone')->default('casual'); // casual, urgent, investigative, fun
    $table->boolean('is_active')->default(true);
    $table->integer('priority')->default(0);   // Для вибору якщо кілька шаблонів одного типу
    $table->timestamps();
});
```

### Таблиця `raffles` — розіграші

```php
Schema::create('raffles', function (Blueprint $table) {
    $table->id();
    $table->string('title');
    $table->text('description');
    $table->string('prize_description');
    $table->string('prize_image_path')->nullable();
    $table->string('participation_method');    // reaction, forward, comment
    $table->string('required_reaction')->nullable(); // 🎲, 🔥, etc.
    $table->integer('max_participants')->nullable();
    $table->timestamp('starts_at');
    $table->timestamp('ends_at');
    $table->bigInteger('announcement_message_id')->nullable();
    $table->bigInteger('result_message_id')->nullable();
    $table->string('winner_tg_username')->nullable();
    $table->bigInteger('winner_tg_id')->nullable();
    $table->string('status')->default('draft'); // draft, active, drawing, completed, cancelled
    $table->json('participants')->nullable();   // [{tg_id, username, joined_at}]
    $table->integer('participants_count')->default(0);
    $table->timestamps();
});
```

### Таблиця `promo_codes` — промокоди

```php
Schema::create('promo_codes', function (Blueprint $table) {
    $table->id();
    $table->string('code', 50);
    $table->string('store', 50);
    $table->string('description');
    $table->string('discount_type');           // percent, fixed, free_shipping
    $table->decimal('discount_value', 10, 2)->nullable();
    $table->timestamp('valid_from')->nullable();
    $table->timestamp('valid_until')->nullable();
    $table->boolean('is_verified')->default(false);
    $table->boolean('is_used_in_post')->default(false);
    $table->string('source')->default('manual'); // manual, parsed, admitad
    $table->timestamps();
});
```

### Таблиця `settings` — налаштування

```php
Schema::create('settings', function (Blueprint $table) {
    $table->id();
    $table->string('key')->unique();
    $table->text('value')->nullable();
    $table->string('type')->default('string'); // string, int, bool, json
    $table->string('group')->default('general'); // general, schedule, ai, telegram, affiliate
    $table->string('description')->nullable();
    $table->timestamps();
});
```

**Дефолтні settings:**
```php
// Група: schedule
'posting_interval_hours' => 3,           // Базовий інтервал
'posting_randomize_minutes' => 25,       // ± рандом (тобто 2:35 — 3:25)
'posting_quiet_start' => '23:00',        // Тиха година (не постити)
'posting_quiet_end' => '08:00',
'posts_per_day_target' => 6,             // Цільова к-сть постів
'rubric_rotation' => true,               // Чергувати рубрики

// Група: ai
'openai_model_text' => 'gpt-4o-mini',
'openai_model_scoring' => 'gpt-4o-mini',
'image_provider' => 'dall-e-3',          // dall-e-3 або replicate
'image_style' => 'vivid',               // vivid або natural
'image_size' => '1024x1024',
'text_language' => 'uk',
'text_tone' => 'casual-fun',            // casual-fun, professional, urgent

// Група: telegram
'channel_id' => '@znyzhka',
'reactions_enabled' => ['🔥', '💩', '😱', '❤️', '🏆'],

// Група: affiliate
'admitad_campaign_id' => '',
'default_affiliate_source' => 'admitad',
```

### Таблиця `post_stats` — детальна статистика

```php
Schema::create('post_stats', function (Blueprint $table) {
    $table->id();
    $table->foreignId('post_id')->constrained()->cascadeOnDelete();
    $table->date('date');
    $table->integer('views')->default(0);
    $table->integer('forwards')->default(0);
    $table->integer('clicks')->default(0);
    $table->json('reactions')->nullable();     // {fire: 5, poop: 1, ...}
    $table->timestamps();
    
    $table->unique(['post_id', 'date']);
});
```

---

## Рубрики (PostType enum)

```php
enum PostType: string
{
    // Основні (щоденні)
    case DEAL = 'deal';                    // 🏷 Звичайна знижка
    case BATTLE = 'battle';                // ⚔️ Битва цін (порівняння магазинів)
    case FAKE_OR_REAL = 'fake_or_real';    // 🕵️ Фейк чи реал?
    case ANOMALY = 'anomaly';              // 🚨 Аномальна ціна
    
    // Дайджести
    case TOP3 = 'top3';                    // 🔥 Топ-3 дня
    case WEEKLY_DIGEST = 'weekly_digest';  // 📊 Тижневий дайджест
    
    // Engagement
    case RAFFLE = 'raffle';                // 🎲 Розіграш
    case PROMO_CODE = 'promo_code';        // 🎟 Промокод дня
    case LIFEHACK = 'lifehack';            // 💡 Лайфхак покупця
    
    // Вірусні
    case SCAM_RATING = 'scam_rating';      // 🏴‍☠️ Рейтинг фейкових знижок
    case PRICE_PREDICTION = 'price_prediction'; // 🔮 Прогноз ціни
}
```

---

## Шаблони постів (Templates) — приклади

### 1. DEAL — Звичайна знижка

**Text prompt (GPT):**
```
Ти — копірайтер TG каналу @znyzhka про знижки в Україні. Пиши УКРАЇНСЬКОЮ.

Створи пост про знижку:
- Товар: {{product_title}}
- Бренд: {{brand}}
- Магазин: {{store}}
- Ціна зараз: {{price}}₴
- Стара ціна: {{old_price}}₴
- Знижка: {{discount_percent}}%
- AI Score: {{ai_score}}/10
- AI вердикт: {{ai_verdict}}
- Промокод: {{promo_code}} (якщо є)

Вимоги:
1. Перший рядок — емодзі + тип + магазин (наприклад: "🏷 ЗНИЖКА | Rozetka")
2. Назва товару жирним
3. Ціна великим текстом із зачеркнутою старою
4. Коротке речення чому це вигідно (1-2 рядки, з гумором або FOMO)
5. AI Score і вердикт
6. CTA з емодзі
7. Останній рядок: заклик до реакції ("🔥 — забираю! | 💩 — фігня")
8. Довжина: 800-1200 символів
9. Тон: дружній, трохи жартівливий, НЕ нав'язливий
10. Emoji помірно (5-8 на пост, не перебір)
11. Не використовуй слова: "спішіть", "тільки сьогодні" (банальщина)

Формат відповіді — тільки текст поста, без пояснень.
```

**Image prompt template:**
```
Product showcase photo for Telegram channel post.
Product: {{product_title}} by {{brand}}.
Style: Clean modern product photography on a subtle gradient background.
Show the actual product prominently in center.
Add a bold red price label "{{price}}₴" in bottom right corner.
Add a crossed-out old price "{{old_price}}₴" in smaller text above it.
Add a "{{discount_percent}}% OFF" badge in top right corner in yellow/red.
Store logo "{{store}}" watermark subtle in corner.
Channel watermark "@znyzhka" in small text at bottom.
Professional commercial quality, NOT AI-looking. Realistic product photo style.
No text other than price and discount labels.
Aspect ratio 1:1.
```

**Inline keyboard:**
```json
[
    [{"text": "🛒 Купити за {{price}}₴", "url": "{{affiliate_url}}"}],
    [{"text": "🔔 Алерт на ціну", "url": "https://t.me/znyzhka_bot?start=alert_{{deal_id}}"}]
]
```

### 2. BATTLE — Битва цін

**Text prompt:**
```
Ти — копірайтер TG каналу @znyzhka. Пиши УКРАЇНСЬКОЮ.

Створи пост "Битва цін" — порівняння одного товару в різних магазинах:
- Товар: {{product_title}}
- Бренд: {{brand}}
- Ціни: {{prices_json}}
  (формат: [{"store": "Rozetka", "price": 5499}, {"store": "Comfy", "price": 5299}, ...])
- Переможець: {{cheapest_store}} ({{cheapest_price}}₴)
- Різниця між мін/макс: {{price_diff}}₴

Вимоги:
1. Перший рядок: "⚔️ БИТВА ЦІН"
2. Назва товару
3. Таблиця цін по магазинах — кожен рядок з емодзі (🏆 для найдешевшого, інші без)
4. "Різниця: {{price_diff}}₴ — ось чому порівнювати ВАЖЛИВО"
5. CTA: посилання на найдешевший
6. Заклик: "Скинь другу який збирався купити! 👆"
7. Тон: об'єктивний, корисний, трохи драматичний
8. Довжина: 600-900 символів

Формат відповіді — тільки текст поста.
```

**Image prompt template:**
```
Price comparison infographic for Telegram channel.
Product: {{product_title}} by {{brand}} centered in image.
Below product show price bars for each store like a bar chart:
{{store_1}}: {{price_1}}₴
{{store_2}}: {{price_2}}₴
{{store_3}}: {{price_3}}₴
Highlight cheapest with green bar and 🏆 icon.
Style: Clean modern infographic, bold colors (red accents), professional.
Text in Ukrainian. Channel "@znyzhka" watermark.
NOT AI-looking, looks like a professionally designed social media graphic.
Aspect ratio 1:1.
```

### 3. FAKE_OR_REAL — Фейк чи реал?

**Text prompt:**
```
Ти — розслідувач фейкових знижок для TG каналу @znyzhka. Пиши УКРАЇНСЬКОЮ.

Створи розслідування знижки:
- Товар: {{product_title}}
- Магазин: {{store}}
- Заявлена знижка: {{claimed_discount}}%
- Заявлена стара ціна: {{claimed_old_price}}₴
- Реальна ціна 1 тиждень тому: {{real_price_week_ago}}₴
- Реальна ціна 1 місяць тому: {{real_price_month_ago}}₴
- Реальна ціна 3 місяці тому: {{real_price_3months_ago}}₴
- Поточна ціна: {{current_price}}₴
- Реальна знижка: {{real_discount}}%

Вимоги:
1. Перший рядок: "🕵️ ФЕЙК ЧИ РЕАЛ?"
2. Покажи що магазин каже vs реальність
3. Графік цін текстом (кожен місяць = рядок)
4. Вердикт: 🟢 РЕАЛ / 🟡 МАРКЕТИНГ / 🔴 ФЕЙК
5. Короткий висновок "Купувати чи ні"
6. Заклик: "Тегни друга який ведеться на 'знижки' 😄"
7. Тон: розслідувальний, троку саркастичний до магазину
8. Довжина: 800-1200 символів

Формат відповіді — тільки текст поста.
```

**Image prompt template:**
```
Investigation-style graphic for Telegram channel about fake discounts.
Product: {{product_title}} photo in center.
Overlay: detective magnifying glass examining a price tag.
Left side: red X with "{{claimed_discount}}% OFF?" text.
Right side: green checkmark with "Reality: {{real_discount}}%" text.
Verdict stamp: "{{verdict}}" (ФЕЙК / МАРКЕТИНГ / РЕАЛ) in large stamp overlay.
Style: newspaper investigation aesthetic, bold typography, slightly dramatic.
Colors: dark background with red/yellow accents.
Channel "@znyzhka" watermark.
Professional graphic design, NOT AI-looking.
Aspect ratio 1:1.
```

### 4. ANOMALY — Аномальна ціна

**Text prompt:**
```
Ти — копірайтер TG каналу @znyzhka. Пиши УКРАЇНСЬКОЮ.

Створи ТЕРМІНОВИЙ пост про аномально низьку ціну:
- Товар: {{product_title}}
- Магазин: {{store}}
- Поточна ціна: {{price}}₴
- Звичайна ціна: {{normal_price}}₴
- Знижка: {{discount_percent}}%
- Коли з'явилась: {{found_time}}
- Можливо помилка: {{maybe_error}}

Вимоги:
1. Перший рядок: "🚨 АНОМАЛІЯ | -{{discount_percent}}%"
2. FOMO елемент (може зникнути, можливо помилка ціни)
3. Чітке порівняння цін
4. "⚡ Знайдено {{found_time}} тому"
5. Терміновий CTA
6. "Кинь другу ЗАРАЗ — може зникнути ⏰"
7. Тон: терміновий, збуджений, але не спамний
8. Довжина: 500-800 символів

Формат відповіді — тільки текст поста.
```

### 5. TOP3 — Топ-3 дня

**Text prompt:**
```
Ти — копірайтер TG каналу @znyzhka. Пиши УКРАЇНСЬКОЮ.

Створи пост "Топ-3 знижки дня":
- Угода 1: {{deal1_title}} — {{deal1_price}}₴ (було {{deal1_old_price}}₴, -{{deal1_discount}}%) — {{deal1_store}}
- Угода 2: {{deal2_title}} — {{deal2_price}}₴ (було {{deal2_old_price}}₴, -{{deal2_discount}}%) — {{deal2_store}}
- Угода 3: {{deal3_title}} — {{deal3_price}}₴ (було {{deal3_old_price}}₴, -{{deal3_discount}}%) — {{deal3_store}}

Вимоги:
1. Перший рядок: "🔥 ТОП-3 ДНЯ | {{date}}"
2. Кожна угода — нумерований блок з емодзі (🥇🥈🥉)
3. Коротке речення про кожну (чому достойна топу)
4. Підсумок: "Сьогодні можна зекономити до {{total_savings}}₴"
5. "Який найкращий? Голосуй реакцією! 🔥 = 1, ❤️ = 2, 😱 = 3"
6. Тон: бадьорий, рейтинговий
7. Довжина: 800-1200 символів

Формат відповіді — тільки текст поста.
```

### 6. RAFFLE — Розіграш

**Text prompt:**
```
Ти — копірайтер TG каналу @znyzhka. Пиши УКРАЇНСЬКОЮ.

Створи пост-розіграш:
- Приз: {{prize_description}}
- Вартість призу: {{prize_value}}₴
- Час початку: зараз
- Час завершення: {{ends_at}}
- Умова участі: {{participation_method}}
- Додаткова умова: бути підписаним на @znyzhka

Вимоги:
1. Перший рядок: "🎲 РОЗІГРАШ"
2. Великий акцент на приз
3. ЧІТКІ і ПРОСТІ умови участі (максимум 3 кроки)
4. Дедлайн великими літерами
5. "Шанси вищі якщо поділишся з другом! 📤"
6. Тон: святковий, щедрий
7. Довжина: 600-900 символів
8. В кінці: "Переможця оберемо ВИПАДКОВО і оголосимо тут!"

Формат відповіді — тільки текст поста.
```

### 7. PROMO_CODE — Промокод дня

**Text prompt:**
```
Ти — копірайтер TG каналу @znyzhka. Пиши УКРАЇНСЬКОЮ.

Створи пост з промокодом:
- Промокод: {{code}}
- Магазин: {{store}}
- Знижка: {{discount_description}} (наприклад: "-15% на все" або "-500₴ від 3000₴")
- Дійсний до: {{valid_until}}
- Категорія: {{category}} (або "на все")

Вимоги:
1. Перший рядок: "🎟 ПРОМОКОД | {{store}}"
2. Промокод ВЕЛИКИМИ в рамці: 「{{code}}」
3. Що дає і де застосувати
4. Дедлайн
5. "Збережи собі і скинь кому треба 👆"
6. Тон: корисний, "ось тобі подарунок"
7. Довжина: 400-600 символів

Формат відповіді — тільки текст поста.
```

### 8. LIFEHACK — Лайфхак покупця

**Text prompt:**
```
Ти — копірайтер TG каналу @znyzhka. Пиши УКРАЇНСЬКОЮ.

Створи корисний лайфхак для покупців:
- Тема: {{topic}}
- Магазин/контекст: {{context}}
- Факти: {{facts}}

Ідеї тем:
- "Як повернути різницю якщо ціна впала після покупки"
- "3 сайти де перевірити реальну ціну"
- "Коли найкращий час купувати [категорію]"
- "Trюк з кошиком — залиште товар і отримайте знижку"
- "Як отримати безкоштовну доставку завжди"

Вимоги:
1. Перший рядок: "💡 ЛАЙФХАК"
2. Корисний контент (реально працюючий трюк)
3. Покрокова інструкція якщо потрібно
4. "Зберігай 🔖 і скидай друзям — комусь точно знадобиться!"
5. Тон: "ось секрет який магазини не хочуть щоб ти знав"
6. Довжина: 600-1000 символів

Формат відповіді — тільки текст поста.
```

### 9. SCAM_RATING — Рейтинг фейкових знижок

**Text prompt:**
```
Ти — аналітик TG каналу @znyzhka. Пиши УКРАЇНСЬКОЮ.

Створи щомісячний рейтинг магазинів за чесністю знижок:
- Період: {{period}} (наприклад "лютий 2026")
- Дані: {{stores_data}}
  (формат: [{"store": "Rozetka", "total_deals": 200, "fake_percent": 22}, ...])

Вимоги:
1. Перший рядок: "🏴‍☠️ РЕЙТИНГ ЧЕСНОСТІ ЗНИЖОК | {{period}}"
2. Рейтинг від найбільшого шахрая до найчеснішого
3. Кожен магазин: позиція + емодзі + % фейків + коротка оцінка
4. Методологія: "Ми перевірили {{total}} акцій за {{period}}"
5. "Згоден з рейтингом? 🔥 — так | 💩 — ні, все не так!"
6. Тон: журналістський, трохи провокативний
7. Довжина: 800-1200 символів
8. Disclaimer: "Рейтинг базується на аналізі публічних цін"

Формат відповіді — тільки текст поста.
```

---

## AI Генерація зображень — ВАЖЛИВО

### Стратегія зображень

НЕ генерувати все з нуля! Комбінований підхід:

```
1. DEAL/ANOMALY → Реальне фото товару з магазину + AI оверлей
2. BATTLE → AI інфографіка (порівняння)
3. FAKE_OR_REAL → Реальне фото + AI детективний оверлей
4. TOP3 → AI колаж з 3 товарів
5. RAFFLE → Повністю AI зображення
6. PROMO_CODE → AI дизайн промокод-картки
7. LIFEHACK → AI ілюстративне зображення
8. SCAM_RATING → AI інфографіка
```

### ImageGenerator сервіс

```php
// Services/AI/ImageGenerator.php

class ImageGenerator
{
    // СТРАТЕГІЯ 1: Для товарів — скачати фото з магазину + додати оверлей
    public function generateDealImage(Deal $deal, string $overlayType = 'price_tag'): string
    {
        // 1. Скачати оригінальне фото товару з deal->image_url
        // 2. Через Intervention Image (PHP) додати:
        //    - Цінник (стара ціна зачеркнута, нова великим)
        //    - Бейдж знижки "−XX%"
        //    - Лого магазину
        //    - Ватермарк @znyzhka
        //    - Рамку/фон відповідного кольору
        // 3. Зберегти результат
        // Це виглядатиме РЕАЛЬНО, бо фото товару справжнє
    }
    
    // СТРАТЕГІЯ 2: Для інфографік — AI генерація
    public function generateInfographic(string $type, array $data): string
    {
        // Використати DALL-E 3 або Replicate Flux
        // Для інфографік/порівнянь/рейтингів
    }
    
    // СТРАТЕГІЯ 3: Для розіграшів — AI генерація
    public function generateRaffleImage(Raffle $raffle): string
    {
        // Яскраве святкове зображення з призом
    }
    
    // СТРАТЕГІЯ 4: Для промокодів — PHP/Canvas генерація
    public function generatePromoCodeCard(PromoCode $promo): string
    {
        // Intervention Image: красива картка з промокодом
        // Великий код по центру, магазин, умови
        // Стиль: як подарунковий купон
    }
}
```

### Для реалістичних зображень товарів — Intervention Image

```php
// Замість AI генерації фото товару, використовуємо реальне фото + PHP оверлей

// composer require intervention/image
// Бібліотека для PHP обробки зображень

class DealImageComposer
{
    public function compose(Deal $deal): string
    {
        // 1. Скачати фото товару з магазину (deal->image_url)
        $productImage = Image::read($deal->image_url);
        
        // 2. Створити canvas 1080x1080 з градієнтним фоном
        $canvas = Image::canvas(1080, 1080, '#1a1a2e');
        
        // 3. Розмістити фото товару по центру (з padding)
        $canvas->place($productImage->resize(700, 700), 'center', 0, -50);
        
        // 4. Бейдж знижки (верхній правий кут)
        // Червоний круг з "-XX%"
        
        // 5. Цінова плашка (внизу)
        // Зачеркнута стара ціна + нова великим шрифтом
        
        // 6. Лого магазину (верхній лівий кут)
        
        // 7. Ватермарк @znyzhka (нижній правий)
        
        // 8. Зберегти
        $path = "posts/deal_{$deal->id}_" . time() . '.jpg';
        Storage::disk('public')->put($path, $canvas->toJpeg(85));
        
        return $path;
    }
}
```

**Це ключовий момент: реальне фото товару + дизайнерський оверлей = виглядає професійно і НЕ аішно.**

Для AI-генерованих зображень (інфографіки, розіграші, лайфхаки) використовувати **DALL-E 3** з детальними промптами.

---

## Розклад постів + рандомізація

### PostScheduler сервіс

```php
// Services/Scheduling/PostScheduler.php

class PostScheduler
{
    /**
     * Генерує розклад постів на день.
     * Базовий інтервал 3 год ± 25 хв рандом.
     * Тиха година 23:00 — 08:00 (не постимо).
     */
    public function generateDailySchedule(Carbon $date): array
    {
        $settings = Setting::getGroup('schedule');
        
        $intervalHours = $settings['posting_interval_hours'];           // 3
        $randomMinutes = $settings['posting_randomize_minutes'];        // 25
        $quietStart = $settings['posting_quiet_start'];                 // 23:00
        $quietEnd = $settings['posting_quiet_end'];                     // 08:00
        $postsTarget = $settings['posts_per_day_target'];               // 6
        
        $slots = [];
        $current = $date->copy()->setTimeFromTimeString($quietEnd);     // Починаємо з 08:00
        $endTime = $date->copy()->setTimeFromTimeString($quietStart);   // Закінчуємо в 23:00
        
        while ($current->lt($endTime) && count($slots) < $postsTarget) {
            // Додати рандомне зміщення: ±randomMinutes
            $offset = rand(-$randomMinutes, $randomMinutes);
            $publishAt = $current->copy()->addMinutes($offset);
            
            // Не раніше quiet_end, не пізніше quiet_start
            if ($publishAt->gte($date->copy()->setTimeFromTimeString($quietEnd)) &&
                $publishAt->lt($endTime)) {
                $slots[] = $publishAt;
            }
            
            $current->addHours($intervalHours);
        }
        
        return $slots;
    }
    
    /**
     * Розподіляє рубрики на слоти дня.
     * Наприклад: 08:xx=deal, 11:xx=battle, 14:xx=fake, 17:xx=top3, 20:xx=promo, 22:xx=raffle
     */
    public function assignRubrics(array $slots, Carbon $date): array
    {
        $dayOfWeek = $date->dayOfWeekIso;
        $isWeekend = $dayOfWeek >= 6;
        
        // Базова ротація рубрик
        $rubricPool = [
            PostType::DEAL,
            PostType::BATTLE,
            PostType::FAKE_OR_REAL,
            PostType::TOP3,
            PostType::PROMO_CODE,
            PostType::DEAL,       // ще одна знижка
        ];
        
        // П'ятниця = розіграш замість останнього deal
        if ($dayOfWeek === 5) {
            $rubricPool[5] = PostType::RAFFLE;
        }
        
        // Понеділок = scam rating
        if ($dayOfWeek === 1) {
            $rubricPool[2] = PostType::SCAM_RATING;
        }
        
        // Середа = lifehack
        if ($dayOfWeek === 3) {
            $rubricPool[4] = PostType::LIFEHACK;
        }
        
        // Неділя = weekly digest замість top3
        if ($dayOfWeek === 7) {
            $rubricPool[3] = PostType::WEEKLY_DIGEST;
        }
        
        $schedule = [];
        foreach ($slots as $i => $slot) {
            $schedule[] = [
                'time' => $slot,
                'type' => $rubricPool[$i] ?? PostType::DEAL,
            ];
        }
        
        return $schedule;
    }
}
```

### Приклад тижневого розкладу

```
Понеділок:
  08:17 — 🏷 Знижка
  11:03 — ⚔️ Битва цін
  14:22 — 🏴‍☠️ Рейтинг фейків (щомісячний)
  17:41 — 🔥 Топ-3 дня
  20:08 — 🎟 Промокод
  22:35 — 🏷 Знижка

Вівторок:
  08:33 — 🏷 Знижка
  10:52 — ⚔️ Битва цін
  14:05 — 🕵️ Фейк чи реал?
  16:48 — 🔥 Топ-3 дня
  20:21 — 🎟 Промокод
  22:12 — 🏷 Знижка

Середа:
  08:07 — 🏷 Знижка
  11:28 — ⚔️ Битва цін
  13:45 — 🕵️ Фейк чи реал?
  17:15 — 🔥 Топ-3 дня
  19:52 — 💡 Лайфхак
  22:30 — 🏷 Знижка

П'ятниця:
  08:22 — 🏷 Знижка
  11:11 — ⚔️ Битва цін
  14:38 — 🕵️ Фейк чи реал?
  17:03 — 🔥 Топ-3 дня
  19:47 — 🎟 Промокод
  22:18 — 🎲 РОЗІГРАШ

Неділя:
  ...
  17:xx — 📊 Тижневий дайджест (замість Топ-3)
```

---

## Адмінка (Filament 3)

### Dashboard

```
┌─────────────────────────────────────────────────────┐
│  📊 znyzhka Admin                                    │
├──────────┬──────────┬──────────┬───────────────────────┤
│ Підписн. │ Перегл.  │ Кліки    │ Дохід (est.)         │
│ 2,847    │ 45.2k    │ 1,203    │ ~18,500₴             │
│ +23 сьог │ за тижд  │ за тижд  │ за місяць            │
├──────────┴──────────┴──────────┴───────────────────────┤
│                                                        │
│  ⏰ Наступні пости:                                    │
│  ┌──────────────────────────────────────────────┐     │
│  │ 14:22 🕵️ Фейк чи реал? — Samsung S24      │ ✏️ │
│  │ 17:41 🔥 Топ-3 дня — [генерується...]      │ ⏳ │
│  │ 20:08 🎟 Промокод — Rozetka -15%           │ ✅ │
│  │ 22:35 🏷 Знижка — JBL Tune 520BT          │ ✅ │
│  └──────────────────────────────────────────────┘     │
│                                                        │
│  📅 Календар постів          🔥 Топ пости тижня       │
│  [Перейти →]                 1. Аномалія JBL (23k 👁) │
│                              2. Битва AirPods (18k 👁) │
│                              3. Фейк Samsung (15k 👁)  │
└────────────────────────────────────────────────────────┘
```

### Сторінки адмінки

**1. Пости (PostResource)**
- Список всіх постів з фільтрами: статус, тип, дата
- Створення/редагування поста вручну
- Кнопка "🤖 Згенерувати AI" — відкриває форму:
  - Обрати тип поста (рубрику)
  - Обрати шаблон
  - Заповнити змінні (або "автозаповнити з Deal")
  - Preview тексту
  - Кнопки "Зберегти як чернетку" / "Запланувати"
- Inline preview: як виглядатиме в TG
- Bulk actions: опублікувати, видалити, перегенерувати тексти

**2. Знижки (DealResource)**
- Список знайдених знижок з парсерів
- Статус: new, scored, used, expired
- AI Score + Verdict
- Кнопка "Створити пост з цієї знижки"
- Фільтри: магазин, категорія, score, статус
- Імпорт: вручну додати URL → система сама парсить

**3. Шаблони (TemplateResource)**
- CRUD шаблонів для кожної рубрики
- Редактор промптів з підсвіткою змінних {{var}}
- Preview: тестова генерація з фейковими даними
- Версіонування (зберігати старі версії промптів)

**4. Розіграші (RaffleResource)**
- Створити розіграш: приз, дати, умови
- Список учасників (парсити реакції з TG)
- Кнопка "Визначити переможця" (рандом)
- Авто-публікація результату

**5. Промокоди (PromoCodeResource)**
- Додавати вручну або парсити з Admitad
- Статус: active, expired, used_in_post
- Прив'язка до магазину

**6. Календар постів (PostCalendar page)**
- Візуальний календар на тиждень/місяць
- Drag & drop для зміни часу публікації
- Кольорове маркування по рубриках
- Порожні слоти: "Немає поста — [Згенерувати]"

**7. Налаштування (Settings page)**
- Telegram: Bot Token, Channel ID
- Розклад: інтервал, рандомізація, тиха година
- AI: модель, стиль зображень, тон текстів
- Affiliate: Admitad ключі
- Рубрики: увімкнути/вимкнути, частота

**8. Аналітика (ChannelStats page)**
- Графік підписників/день
- Графік переглядів/день
- Найпопулярніші рубрики (по переглядах, реакціях, кліках)
- Click-through rate по магазинах
- Естимейт доходу (кліки × avg conversion × avg commission)

---

## Вірусні механіки (вбудовані в систему)

### 1. Автоматичний CTA в кожному пості

В кожен шаблон вбудований заклик до дії:
```
DEAL:       "🔥 — забираю! | 💩 — фігня"
BATTLE:     "Скинь другу який збирався купити! 👆"
FAKE_REAL:  "Тегни друга який ведеться на 'знижки' 😄"
ANOMALY:    "Кинь другу ЗАРАЗ — може зникнути ⏰"
TOP3:       "Який найкращий? 🔥=1, ❤️=2, 😱=3"
RAFFLE:     "Шанси вищі якщо друг теж підписаний! 📤"
PROMO_CODE: "Збережи собі і скинь кому треба 👆"
LIFEHACK:   "Зберігай 🔖 і скидай друзям"
SCAM_RATING:"Згоден? 🔥 — так | 💩 — все не так!"
```

### 2. "Серіали" (multi-part контент)

Деякі пости — частина серії:
```
"🕵️ ФЕЙК ЧИ РЕАЛ? Частина 47"
"⚔️ БИТВА ЦІН #23"
"🏴‍☠️ РЕЙТИНГ ЧЕСНОСТІ — Березень 2026"
```
Нумерація = відчуття що канал давно працює, є архів, є довіра.

### 3. User mentions в розіграшах

Коли оголошується переможець:
```
"🏆 Переможець: @username! Вітаємо!"
```
Друзі переможця бачать → підписуються.

### 4. Провокативний контент (Scam Rating)

Посту з рейтингом фейкових знижок ГАРАНТОВАНО будуть сперечатись і шерити — це природня вірусність.

### 5. Utility content (Lifehacks, Battle)

Bitвa цін і лайфхаки — це **корисний контент** який скрінять і кидають друзям навіть ті хто не підписаний.

---

## Повний pipeline генерації поста

```
1. Scheduler визначає: "14:22 треба пост типу FAKE_OR_REAL"
2. Job GeneratePostContent:
   a. Знайти невикористану знижку (Deal) з ai_score + price_history
   b. Обрати Template для FAKE_OR_REAL
   c. Підставити змінні в промпт
   d. GPT-4o-mini генерує текст
   e. ImageGenerator:
      - Скачати фото товару з deal.image_url
      - Через Intervention Image накласти "детективний" оверлей
      - Зберегти зображення
   f. Зібрати inline keyboard (кнопка "Купити", реакції)
   g. Зберегти Post(status=ready, scheduled_at=14:22)

3. PublishToTelegramJob (о 14:22):
   a. Взяти Post
   b. Відправити фото + текст в канал через Nutgram:
      Bot::sendPhoto(channelId, photo, caption, replyMarkup)
   c. Зберегти telegram_message_id
   d. Post.status = published

4. TrackPostStatsJob (кожну годину):
   a. Для кожного поста за 7 днів:
   b. Через TG Bot API отримати view count
   c. Зберегти в post_stats
```

---

## Парсери магазинів

### ParserInterface

```php
interface ParserInterface
{
    public function parse(): Collection;   // Returns Collection of Deal DTOs
    public function getStoreName(): string;
}
```

### RozetkaParser (пріоритет #1)

```php
// Джерела:
// 1. RSS feed акцій (якщо доступний)
// 2. Парсинг сторінки https://rozetka.com.ua/promotions/
// 3. API каталогу (публічний JSON endpoint)

// Парсити:
// - title, price, old_price (discount = different)
// - image_url (перше фото товару)
// - url
// - category (з breadcrumbs або URL)
// - brand (з title або specs)

// Унікальність: перевіряти по url щоб не дублювати
```

### DealScorer (AI оцінка)

```php
// Після парсингу кожен Deal оцінюється через GPT-4o-mini:

$prompt = "Оціни якість знижки 1-10. Відповідь JSON.
Товар: {$title}
Ціна: {$price}₴, Стара: {$old_price}₴, Знижка: {$percent}%

Критерії:
1-3: фейк, <10%, нікому не цікаво
4-6: нормально, але не вау
7-8: хороша знижка на популярне
9-10: must buy, аномальна ціна

JSON: {\"score\": N, \"verdict\": \"...\", \"is_likely_fake\": bool}";
```

---

## .env

```
APP_NAME=Znyzhka
APP_URL=https://admin.znyzhka.com

TELEGRAM_BOT_TOKEN=xxx
TELEGRAM_CHANNEL_ID=@znyzhka

OPENAI_API_KEY=xxx
OPENAI_MODEL=gpt-4o-mini
OPENAI_IMAGE_MODEL=dall-e-3

REPLICATE_API_TOKEN=xxx

ADMITAD_CAMPAIGN_ID=xxx
ADMITAD_API_KEY=xxx

ADMIN_EMAIL=admin@znyzhka.com
ADMIN_PASSWORD=xxx

DB_CONNECTION=mysql
DB_DATABASE=znyzhka

REDIS_HOST=redis
QUEUE_CONNECTION=redis
CACHE_STORE=redis
```

---

## Порядок реалізації

### Фаза 1 (Тиждень 1): Ядро

1. Laravel + Filament 3 setup
2. Всі міграції (posts, deals, templates, raffles, promo_codes, settings, post_stats)
3. Моделі з relationships
4. Enums (PostType, PostStatus, DealCategory, StoreType)
5. Setting model з дефолтами
6. Filament auth (email + password)
7. Базовий Dashboard

### Фаза 2 (Тиждень 1-2): Контент

8. PostTextGenerator (GPT інтеграція)
9. DealImageComposer (Intervention Image: реальне фото + оверлей)
10. ImageGenerator (DALL-E 3 для інфографік)
11. Усі 9 шаблонів (Templates) з промптами як seed
12. Template CRUD в адмінці з preview
13. Сторінка "Згенерувати пост" в адмінці (вибір рубрики → AI → preview → save)

### Фаза 3 (Тиждень 2): Автоматизація

14. PostScheduler (генерація розкладу з рандомізацією)
15. RubricRotator (ротація рубрик по днях тижня)
16. PublishToTelegramJob (публікація з фото + кнопками)
17. Scheduler command: `schedule:generate-daily` + `posts:publish`
18. Календар постів в адмінці

### Фаза 4 (Тиждень 2-3): Парсери

19. ParserInterface + RozetkaParser
20. DealScorer (AI оцінка знижок)
21. ParseDealsJob (cron кожні 30хв)
22. DealResource в адмінці
23. Affiliate link generation (AdmitadService)

### Фаза 5 (Тиждень 3): Engagement

24. RaffleResource + ProcessRaffleJob
25. PromoCode management
26. TrackPostStatsJob (збір view/reaction stats)
27. ChannelStats page в адмінці
28. Додаткові парсери (Comfy, Allo)

---

## КРИТИЧНІ ВИМОГИ

1. **Зображення товарів — РЕАЛЬНІ фото з магазинів + PHP overlay, НЕ AI-генеровані**
2. **AI генерує тільки інфографіки, розіграші, промо-картки**
3. **Всі тексти УКРАЇНСЬКОЮ** (промпти до GPT чітко кажуть "пиши УКРАЇНСЬКОЮ")
4. **Рандомізація часу** — кожен пост ± до 25 хвилин від базового часу
5. **Тиха година** 23:00-08:00 — нічого не постити
6. **Шаблони редагуються в адмінці** — не хардкодити промпти
7. **Preview перед публікацією** — адмін бачить як виглядатиме в TG
8. **Affiliate лінк в КОЖНОМУ пості** де є товар
9. **Filament 3** для адмінки (не писати свій UI)
10. **Queue для всього важкого** — AI генерація, парсинг, публікація
11. **Intervention Image v3** для обробки зображень

Почни з Фази 1: Laravel + Filament + міграції + моделі + enums + базова адмінка.
