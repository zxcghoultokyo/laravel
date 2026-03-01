# HotUA — Промпт для створення проекту

Скопіюй все нижче і вставляй в новий проект як початковий промпт.

---

## ПРОМПТ:

Створи Laravel 12 проект "HotUA" — гейміфікований Telegram бот + канал зі знижками для українського ринку.

### Що це
Український аналог Pepper.com, але в Telegram. Два компоненти:
1. **TG канал @hotua** — автоматична стрічка найкращих знижок з Rozetka, Comfy, Allo, AliExpress та інших
2. **TG бот @hotua_bot** — персоналізація, гейміфікація, алерти на ціни

Монетизація: affiliate посилання через Admitad/SalesDoubler (2.5-8% від кожної покупки через наш лінк).

### Бізнес-модель
- Парсимо знижки з магазинів → AI оцінює якість знижки → постимо в канал з affiliate лінком
- Бот тримає юзера залученим через гейміфікацію (колесо фортуни, монети, рівні) → юзер щодня заходить → бачить угоди → клікає → купує → ми отримуємо affiliate комісію
- Орієнтовно 80-150₴ за підтверджену покупку

### Конкурентне середовище
- Ніша в Україні ПОРОЖНЯ. Єдиний конкурент @ukrainealiexpress_bot (~20k MAU) — без гейміфікації, тільки AliExpress
- Pepper.com не має української версії
- GoToShop.ua — тільки продукти/супермаркети, 116 підписників в TG
- Всі інші канали зі знижками мертві (3-27 підписників)

### Технічний стек
- **Backend:** Laravel 12 + PHP 8.3
- **TG Bot:** Nutgram (https://nutgram.dev) — Laravel-native Telegram Bot framework
- **DB:** MySQL/PostgreSQL
- **Queue:** Redis + Laravel Queue (для парсингу, розсилок)
- **AI:** OpenAI API (GPT-4o-mini) — для оцінки якості знижок та генерації текстів
- **Cache:** Redis
- **Scheduler:** Laravel Schedule (cron-парсинг кожні 30 хв)

### Архітектура

```
Laravel Backend
├── Services/
│   ├── Parsers/                    # Парсери магазинів
│   │   ├── RozetkaParser.php       # RSS/API Rozetka
│   │   ├── ComfyParser.php
│   │   ├── AlloParser.php
│   │   ├── AliExpressParser.php
│   │   └── ParserInterface.php
│   ├── Affiliate/
│   │   ├── AdmitadService.php      # Генерація affiliate лінків
│   │   └── LinkGenerator.php       # Обгортка URL в affiliate
│   ├── AI/
│   │   └── DealScorer.php          # AI оцінка якості знижки (1-10)
│   ├── Gamification/
│   │   ├── CoinService.php         # Логіка монет
│   │   ├── LevelService.php        # Рівні та XP
│   │   ├── WheelService.php        # Колесо фортуни
│   │   └── ReferralService.php     # Реферальна система
│   ├── Bot/
│   │   ├── Handlers/
│   │   │   ├── StartHandler.php        # /start + онбординг
│   │   │   ├── ProfileHandler.php      # /profile
│   │   │   ├── WheelHandler.php        # /wheel (колесо)
│   │   │   ├── AlertHandler.php        # /alert (алерти на ціну)
│   │   │   ├── CategoriesHandler.php   # Вибір/зміна категорій
│   │   │   └── SubmitDealHandler.php   # Юзер подає угоду (UGC)
│   │   └── Keyboards/
│   │       ├── MainKeyboard.php
│   │       ├── CategoryKeyboard.php
│   │       └── WheelKeyboard.php
│   └── Channel/
│       ├── DealPublisher.php       # Публікація угод в канал
│       └── DealFormatter.php       # Форматування пост
├── Jobs/
│   ├── ParseDealsJob.php           # Парсинг знижок (queue)
│   ├── PublishDealJob.php          # Публікація в канал
│   ├── SendPersonalDealJob.php     # Персональна розсилка по категоріях
│   ├── DailyWheelReminderJob.php   # Нагадування крутити колесо
│   └── WeeklyRaffleJob.php         # Щотижневий розіграш
├── Models/
│   ├── User.php                    # tg_id, username, coins, xp, level, referred_by
│   ├── Deal.php                    # store, title, price, old_price, url, affiliate_url, ai_score, category, posted_at
│   ├── UserCategory.php            # user_id, category
│   ├── DealClick.php               # user_id, deal_id, clicked_at (трекінг)
│   ├── DailySpin.php               # user_id, date, reward_type, reward_value
│   ├── PriceAlert.php              # user_id, query, max_price, category, active
│   ├── Referral.php                # referrer_id, referred_id, earned_coins
│   └── DealVote.php                # deal_id, user_id, vote (hot/cold)
└── Console/Commands/
    ├── ParseDeals.php              # artisan deals:parse
    ├── PublishToChannel.php        # artisan deals:publish
    └── SendAlerts.php              # artisan alerts:check
```

### База даних (міграції)

```sql
-- users
CREATE TABLE users (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    tg_id BIGINT UNIQUE NOT NULL,
    username VARCHAR(255) NULL,
    first_name VARCHAR(255) NULL,
    coins INT DEFAULT 0,
    xp INT DEFAULT 0,
    level INT DEFAULT 1,
    referred_by BIGINT NULL,  -- tg_id того, хто запросив
    onboarded_at TIMESTAMP NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);

-- user_categories (які категорії юзер обрав)
CREATE TABLE user_categories (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT NOT NULL,
    category VARCHAR(50) NOT NULL,  -- tech, fashion, gaming, home, beauty, kids, kitchen, auto, travel, books, sport, tools
    created_at TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    UNIQUE KEY (user_id, category)
);

-- deals
CREATE TABLE deals (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    store VARCHAR(100) NOT NULL,        -- rozetka, comfy, allo, aliexpress
    title VARCHAR(500) NOT NULL,
    description TEXT NULL,
    price DECIMAL(10,2) NOT NULL,
    old_price DECIMAL(10,2) NULL,
    discount_percent INT NULL,
    url VARCHAR(1000) NOT NULL,         -- оригінальне посилання
    affiliate_url VARCHAR(1000) NULL,   -- affiliate посилання
    image_url VARCHAR(1000) NULL,
    category VARCHAR(50) NOT NULL,
    ai_score TINYINT NULL,              -- 1-10 якість знижки
    ai_comment VARCHAR(500) NULL,       -- "реальна знижка" / "фейкова"
    promo_code VARCHAR(100) NULL,       -- промокод якщо є
    is_published BOOLEAN DEFAULT FALSE,
    published_at TIMESTAMP NULL,
    channel_message_id BIGINT NULL,     -- ID повідомлення в каналі
    votes_hot INT DEFAULT 0,
    votes_cold INT DEFAULT 0,
    clicks_count INT DEFAULT 0,
    source VARCHAR(50) DEFAULT 'parser',  -- parser, user_submitted, manual
    submitted_by BIGINT NULL,             -- tg_id якщо подав юзер
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    INDEX (category),
    INDEX (store),
    INDEX (ai_score),
    INDEX (is_published)
);

-- deal_clicks (трекінг)
CREATE TABLE deal_clicks (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT NOT NULL,
    deal_id BIGINT NOT NULL,
    clicked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (deal_id) REFERENCES deals(id)
);

-- deal_votes
CREATE TABLE deal_votes (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    deal_id BIGINT NOT NULL,
    user_id BIGINT NOT NULL,
    vote ENUM('hot', 'cold') NOT NULL,
    created_at TIMESTAMP,
    FOREIGN KEY (deal_id) REFERENCES deals(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    UNIQUE KEY (deal_id, user_id)
);

-- daily_spins (колесо фортуни)
CREATE TABLE daily_spins (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT NOT NULL,
    spin_date DATE NOT NULL,
    reward_type ENUM('coins', 'xp', 'promo', 'double_cashback', 'raffle_ticket', 'nothing') NOT NULL,
    reward_value INT NULL,                -- кількість монет/XP
    reward_meta JSON NULL,                -- промокод, деталі
    created_at TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    UNIQUE KEY (user_id, spin_date)
);

-- price_alerts
CREATE TABLE price_alerts (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT NOT NULL,
    query VARCHAR(255) NOT NULL,          -- "airpods pro"
    max_price DECIMAL(10,2) NULL,         -- сповістити коли < цієї ціни
    category VARCHAR(50) NULL,
    is_active BOOLEAN DEFAULT TRUE,
    last_notified_at TIMESTAMP NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- referrals
CREATE TABLE referrals (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    referrer_id BIGINT NOT NULL,
    referred_id BIGINT NOT NULL,
    coins_earned INT DEFAULT 0,
    created_at TIMESTAMP,
    FOREIGN KEY (referrer_id) REFERENCES users(id),
    FOREIGN KEY (referred_id) REFERENCES users(id),
    UNIQUE KEY (referred_id)
);

-- raffles (розіграші)
CREATE TABLE raffles (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    prize_description TEXT NOT NULL,
    starts_at TIMESTAMP,
    ends_at TIMESTAMP,
    winner_user_id BIGINT NULL,
    is_completed BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP
);

-- raffle_tickets
CREATE TABLE raffle_tickets (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    raffle_id BIGINT NOT NULL,
    user_id BIGINT NOT NULL,
    source ENUM('spin', 'purchase', 'referral', 'manual') NOT NULL,
    created_at TIMESTAMP,
    FOREIGN KEY (raffle_id) REFERENCES raffles(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

### Категорії (константа)

```php
// app/Enums/DealCategory.php
enum DealCategory: string
{
    case TECH = 'tech';           // 🖥 Техніка & гаджети
    case FASHION = 'fashion';     // 👟 Одяг & взуття
    case GAMING = 'gaming';       // 🎮 Геймінг
    case HOME = 'home';           // 🏠 Дім & інтер'єр
    case BEAUTY = 'beauty';       // 💄 Краса & здоров'я
    case KIDS = 'kids';           // 👶 Дитячі товари
    case KITCHEN = 'kitchen';     // 🍳 Кухня & побут
    case AUTO = 'auto';           // 🚗 Авто
    case TRAVEL = 'travel';       // ✈️ Подорожі
    case BOOKS = 'books';         // 📚 Книги & навчання
    case SPORT = 'sport';         // 🏋️ Спорт
    case TOOLS = 'tools';         // 🔧 Інструменти
}
```

### Бот: Онбординг (/start)

Послідовність:
1. Юзер натискає /start (або переходить по реферальному лінку /start?ref=123456)
2. Бот вітає: "🔥 Привіт! Я HotUA — знаходжу найкращі знижки для тебе."
3. Показує inline-клавіатуру з 12 категоріями (multi-select, до 5 штук)
4. Юзер обирає → зберігається в user_categories
5. Бот: "Готово! Тепер ти отримуватимеш знижки тільки в цих категоріях. Підпишись на канал @hotua для загальної стрічки!"
6. Показує головне меню (reply keyboard):
   - 🎡 Колесо фортуни
   - 👤 Мій профіль
   - 🔔 Мої алерти
   - ⚙️ Категорії
   - 📤 Подати знижку

### Бот: Колесо фортуни (/wheel)

- Доступне раз на добу (00:00 Kyiv time скидається)
- Сектори колеса (з вагами):
  - 💰 50 монет (30%)
  - 💰 100 монет (15%)
  - 💰 200 монет (5%)
  - ⭐ +50 XP (20%)
  - 🎟 Квиток розіграшу (10%)
  - 🔥 Подвійний кешбек на 1 покупку (8%)
  - 🎁 Ексклюзивний промокод (7%)
  - 📱 iPhone (0.01%) — near miss 4.99%
- Якщо юзер вже крутив сьогодні → "Наступний спін через X годин Y хвилин"
- Після спіну: "Поділись результатом з другом і отримай +50 монет!" (реферальне посилання)

### Бот: Профіль (/profile)

```
👤 Тарас (@taras_ua)
💰 Монети: 1,250
⭐ XP: 3,400 / 5,000 (до рівня 6)
🏆 Рівень: 5 (Мисливець)
👥 Рефералів: 7
🎟 Квитків розіграшу: 3
📊 Кліків по знижках: 47

Рівні:
1 — Новачок (0 XP)
3 — Мисливець (1000 XP) ← ранній доступ до знижок
5 — Снайпер (3000 XP) ← ексклюзивні промокоди
10 — Легенда (10000 XP) ← VIP угоди + подвійні монети
```

### Бот: Алерти (/alert)

```
Юзер: /alert airpods pro 5000
Бот: ✅ Алерт створено!
     Я повідомлю коли AirPods Pro буде < 5000₴
     Зараз найкраща ціна: 5499₴ (Rozetka)

Коли ціна впаде:
Бот: 🔔 АЛЕРТ! AirPods Pro впав до 4899₴!
     Магазин: Comfy
     Було: 5499₴ → Зараз: 4899₴ (-11%)
     [Купити →] (affiliate link)
```

### Канал: Формат постів

```
🔥 ЗНИЖКА | Rozetka

Sony WH-1000XM5 (навушники)
💰 7,499₴ замість 11,999₴ (-38%)

📊 HotUA Score: 9/10
✅ Найнижча ціна за 6 місяців
⚡ Наявність: є на складі

🛒 Купити → https://ad.admitad.com/g/xxx/?ulp=https://rozetka.com.ua/...

🔥 12  |  ❄️ 1  |  👁 234
```

Кнопки під постом (inline):
- 🔥 Hot (голос)
- ❄️ Cold (голос)
- 🛒 Купити (affiliate link)
- 🔔 Алерт на цей товар

### Парсери: Джерела знижок

**Rozetka:**
- RSS: https://rozetka.com.ua/rss/promotions/ (якщо є)
- Парсинг сторінки акцій: https://rozetka.com.ua/promotions/
- API (якщо є): перевірити наявність публічного API
- Категорії: електроніка, побутова техніка, одяг

**Comfy:**
- Каталог акцій: https://comfy.ua/akcii/
- Парсинг категорій зі знижками

**Allo:**
- Акції: https://allo.ua/ua/special-offers/
- Їх API може бути доступний

**AliExpress:**
- AliExpress Affiliate API (portals.aliexpress.com)
- Парсинг flash deals

**Пріоритет парсингу:** Rozetka > Allo > Comfy > AliExpress

### AI DealScorer

```php
// Services/AI/DealScorer.php
// Оцінка знижки через GPT-4o-mini

$prompt = "Оціни якість цієї знижки від 1 до 10.
Товар: {$deal->title}
Ціна зараз: {$deal->price}₴
Стара ціна: {$deal->old_price}₴
Знижка: {$deal->discount_percent}%
Магазин: {$deal->store}

Критерії:
- 1-3: фейкова знижка (підняли ціну перед акцією), незначна (<5%), непопулярний товар
- 4-6: нормальна знижка, але нічого особливого
- 7-8: хороша знижка на популярний товар
- 9-10: аномально низька ціна, must-buy

Відповідь JSON: {\"score\": 8, \"comment\": \"Реальна знижка 38%, найнижча ціна на ринку\"}";
```

Публікувати в канал тільки угоди з score >= 7.

### Affiliate інтеграція

```php
// Services/Affiliate/AdmitadService.php

class AdmitadService
{
    // Генерація deeplink
    public function generateLink(string $originalUrl): string
    {
        $campaignId = config('services.admitad.campaign_id');
        return "https://ad.admitad.com/g/{$campaignId}/?" . http_build_query([
            'ulp' => $originalUrl,
        ]);
    }
}

// config/services.php
'admitad' => [
    'campaign_id' => env('ADMITAD_CAMPAIGN_ID'),
    'api_key' => env('ADMITAD_API_KEY'),
    'api_secret' => env('ADMITAD_API_SECRET'),
],
```

### Гейміфікація: Рівні

```php
// Services/Gamification/LevelService.php

const LEVELS = [
    1 => ['name' => 'Новачок', 'xp' => 0, 'emoji' => '🌱'],
    2 => ['name' => 'Шукач', 'xp' => 500, 'emoji' => '🔍'],
    3 => ['name' => 'Мисливець', 'xp' => 1000, 'emoji' => '🏹'],
    5 => ['name' => 'Снайпер', 'xp' => 3000, 'emoji' => '🎯'],
    7 => ['name' => 'Експерт', 'xp' => 6000, 'emoji' => '💎'],
    10 => ['name' => 'Легенда', 'xp' => 10000, 'emoji' => '👑'],
];

// Нарахування XP:
// Щоденний спін: +10 XP
// Клік по угоді: +5 XP
// Голосування: +2 XP
// Подав угоду що опублікували: +100 XP
// Запросив друга: +200 XP
```

### Персональна розсилка

```php
// Jobs/SendPersonalDealJob.php
// Коли нова угода з score >= 7:

$deal = Deal::find($dealId);

// Знайти юзерів підписаних на цю категорію
$users = User::whereHas('categories', function ($q) use ($deal) {
    $q->where('category', $deal->category);
})->pluck('tg_id');

// Розсилка з rate limiting (TG дозволяє 30 msg/sec)
foreach ($users->chunk(25) as $chunk) {
    foreach ($chunk as $tgId) {
        // Формат персонального повідомлення (коротший ніж в каналі)
        $bot->sendMessage($tgId, $deal->formatPersonalMessage(), [
            'reply_markup' => InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('🛒 Купити', url: $deal->affiliate_url))
                ->addRow(InlineKeyboardButton::make('🔔 Алерт на цей товар', callback_data: "alert:{$deal->id}"))
        ]);
    }
    sleep(1); // rate limit
}
```

### Scheduler (cron)

```php
// app/Console/Kernel.php або routes/console.php

Schedule::command('deals:parse rozetka')->everyThirtyMinutes();
Schedule::command('deals:parse comfy')->hourly();
Schedule::command('deals:parse allo')->hourly();
Schedule::command('deals:parse aliexpress')->everyTwoHours();
Schedule::command('deals:publish')->everyFifteenMinutes();  // публікація найкращих в канал
Schedule::command('alerts:check')->everyThirtyMinutes();     // перевірка алертів
Schedule::command('wheel:remind')->dailyAt('10:00');          // нагадай крутити колесо
Schedule::command('raffle:draw')->weeklyOn(5, '18:00');       // розіграш щоп'ятниці
```

### .env конфіг

```
APP_NAME=HotUA
TELEGRAM_BOT_TOKEN=xxx
TELEGRAM_CHANNEL_ID=@hotua
ADMITAD_CAMPAIGN_ID=xxx
ADMITAD_API_KEY=xxx
ADMITAD_API_SECRET=xxx
OPENAI_API_KEY=xxx
OPENAI_MODEL=gpt-4o-mini
```

### Порядок розробки (фази)

**Фаза 1 (тиждень 1-2): MVP**
1. Laravel проект + Nutgram setup
2. Міграції всіх таблиць
3. /start з онбордингом (вибір категорій)
4. Парсер Rozetka (першим — найбільший магазин)
5. AI DealScorer
6. Автопублікація в канал
7. Affiliate лінки (Admitad deeplink)
8. Базова персональна розсилка по категоріях

**Фаза 2 (тиждень 3): Гейміфікація**
1. Колесо фортуни
2. Монети + XP + рівні
3. Профіль юзера
4. Реферальна система

**Фаза 3 (тиждень 4): Engagement**
1. Алерти на ціну
2. Голосування 🔥/❄️
3. UGC (юзери подають знижки)
4. Додаткові парсери (Comfy, Allo, AliExpress)
5. Щотижневий розіграш

### ВАЖЛИВО
- Вся взаємодія тільки через Telegram (бот + канал). Без веб-інтерфейсу
- Всі тексти бота УКРАЇНСЬКОЮ мовою
- Rate limits TG: не більше 30 повідомлень/сек, 20 повідомлень в хвилину в один чат
- Affiliate лінки мають бути прозорими (юзер бачить що йде на Rozetka, а не на підозрілий redirect)
- Cookie Admitad живе 30 днів — юзер може купити пізніше
- Публікувати в канал тільки угоди з AI Score >= 7
- Формат порівняння цін ("Битва Цін") — окремий тип постів де один товар порівнюється в різних магазинах

Почни з Фази 1: створи структуру проекту, міграції, моделі, Nutgram бот з /start онбордингом, і парсер Rozetka.
