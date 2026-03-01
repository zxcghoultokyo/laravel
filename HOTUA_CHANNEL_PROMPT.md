# HotUA — Лови гарячі знижки! | TG Канал | ТЗ-Промпт

Канал: https://t.me/zlovyv (@zlovyv)

Скопіюй все нижче в новий проект як стартовий промпт.

---

## ПРОМПТ

Створи Laravel 12 (PHP 8.4) проект "HotUA" — автоматизований Telegram канал зі знижками для українського ринку з AI-генерацією контенту (тексти + зображення) та адмін-панеллю.

### Концепція

**"HotUA — Лови гарячі знижки!"** — повністю автоматизований TG канал @zlovyv який:
- Публікує 5-8 постів/день по розкладу з рандомізацією часу
- Кожен пост з AI-згенерованим текстом + зображенням
- Різні рубрики (знижка, битва цін, фейк чи реал, тощо)
- Голосування через TG реакції + inline кнопки
- Розіграші та промокоди для залучення
- Affiliate лінки у кожному пості
- Все керується через захищену веб-адмінку

Монетизація: affiliate маркетинг (2.5-8% від покупок через наші посилання).

---

## Технічний стек

- **Backend:** Laravel 12, PHP 8.4
- **Admin UI:** Filament 3 (Laravel admin panel, з коробки дає CRUD, auth, dashboard)
- **TG API:** Nutgram (nutgram/nutgram) — Laravel-native Telegram SDK
- **AI Тексти:** OpenAI API (GPT-4o-mini для генерації, GPT-4o для складних)
- **AI Зображення:** OpenAI DALL-E 3 API + Replicate API (Flux Schnell як fallback/альтернатива)
- **DB:** MySQL
- **Queue:** Redis + Laravel Queue
- **Scheduler:** Laravel Schedule + рандомізація
- **Cache:** Redis
- **Storage:** S3-сумісне сховище (або local для dev) для згенерованих зображень
- **Image Processing:** Intervention Image v3

---

## Affiliate архітектура (ВАЖЛИВО!)

### Принцип: Affiliate-first

Ми монетизуємо ТІЛЬКИ через affiliate. Тому:
- Кожне посилання в пості = affiliate лінк
- Якщо на товар немає affiliate програми → не постимо його
- Джерело знижок визначається наявністю affiliate оферти

### Multi-provider affiliate система

Стартуємо з **Admitad**, але архітектура готова до розширення.

```php
// Контракт для будь-якого affiliate провайдера
interface AffiliateProviderInterface
{
    public function getName(): string;                    // 'admitad', 'salesdoubler', 'direct_rozetka'
    public function generateLink(string $originalUrl): ?string;
    public function getAvailableStores(): array;          // Які магазини доступні через цього провайдера
    public function supportsStore(string $storeDomain): bool;
    public function getCommissionRate(string $storeDomain): ?float;
    public function isActive(): bool;
}
```

```
app/Services/Affiliate/
├── AffiliateProviderInterface.php     # Контракт
├── AffiliateManager.php               # Фасад: обирає кращого провайдера для URL
├── Providers/
│   ├── AdmitadProvider.php            # Admitad (старт)
│   ├── SalesDoublerProvider.php       # SalesDoubler (фаза 2)
│   └── DirectAffiliateProvider.php    # Прямі партнерки магазинів (фаза 3)
└── LinkWrapper.php                    # Утиліта обгортки URL
```

### AffiliateManager — центральний сервіс

```php
class AffiliateManager
{
    private array $providers = [];
    
    // Зареєструвати провайдера (з конфігу)
    public function registerProvider(AffiliateProviderInterface $provider): void;
    
    // Знайти найкращого провайдера для URL і згенерити лінк
    public function wrapUrl(string $originalUrl): ?AffiliateLink
    {
        $domain = parse_url($originalUrl, PHP_URL_HOST);
        
        // Пріоритет: хто дає більшу комісію
        $bestProvider = null;
        $bestRate = 0;
        
        foreach ($this->providers as $provider) {
            if (!$provider->isActive()) continue;
            if (!$provider->supportsStore($domain)) continue;
            
            $rate = $provider->getCommissionRate($domain);
            if ($rate > $bestRate) {
                $bestRate = $rate;
                $bestProvider = $provider;
            }
        }
        
        if (!$bestProvider) return null;  // Немає affiliate → не постити
        
        return new AffiliateLink(
            originalUrl: $originalUrl,
            affiliateUrl: $bestProvider->generateLink($originalUrl),
            providerName: $bestProvider->getName(),
            commissionRate: $bestRate,
        );
    }
    
    // Чи є для цього магазину хоча б один провайдер?
    public function hasAffiliateFor(string $url): bool;
    
    // Список всіх підтримуваних магазинів (union всіх провайдерів)
    public function getAllSupportedStores(): array;
}
```

### AffiliateLink DTO

```php
readonly class AffiliateLink
{
    public function __construct(
        public string $originalUrl,
        public string $affiliateUrl,
        public string $providerName,    // 'admitad'
        public float $commissionRate,   // 0.05 = 5%
    ) {}
}
```

### AdmitadProvider (перша реалізація)

```php
class AdmitadProvider implements AffiliateProviderInterface
{
    public function getName(): string => 'admitad';
    
    public function generateLink(string $originalUrl): ?string
    {
        $campaignId = config('affiliate.admitad.campaign_id');
        if (!$campaignId) return null;
        
        return "https://ad.admitad.com/g/{$campaignId}/?" . http_build_query([
            'ulp' => $originalUrl,
        ]);
    }
    
    public function getAvailableStores(): array
    {
        // Повертає з конфігу або кешу список магазинів з Admitad
        return config('affiliate.admitad.stores', []);
    }
    
    public function supportsStore(string $storeDomain): bool
    {
        return in_array($storeDomain, array_keys($this->getAvailableStores()));
    }
    
    public function getCommissionRate(string $storeDomain): ?float
    {
        return $this->getAvailableStores()[$storeDomain]['rate'] ?? null;
    }
    
    public function isActive(): bool
    {
        return !empty(config('affiliate.admitad.campaign_id'));
    }
}
```

### affiliate.php конфіг

```php
// config/affiliate.php
return [
    // Порядок пріоритету провайдерів (якщо однакова комісія)
    'provider_priority' => ['admitad', 'salesdoubler', 'direct'],
    
    'admitad' => [
        'enabled' => env('ADMITAD_ENABLED', true),
        'campaign_id' => env('ADMITAD_CAMPAIGN_ID'),
        'api_key' => env('ADMITAD_API_KEY'),
        'api_secret' => env('ADMITAD_API_SECRET'),
        // Магазини та комісії (оновлювати з адмінки або Admitad API)
        'stores' => [
            'rozetka.com.ua' => ['name' => 'Rozetka', 'rate' => 0.035],     // 3.5%
            'comfy.ua' => ['name' => 'Comfy', 'rate' => 0.03],
            'allo.ua' => ['name' => 'Allo', 'rate' => 0.025],
            'epicentrk.ua' => ['name' => 'Епіцентр', 'rate' => 0.04],
            'apteka24.ua' => ['name' => 'Аптека24', 'rate' => 0.05],
            'stylus.ua' => ['name' => 'Stylus', 'rate' => 0.03],
            'prom.ua' => ['name' => 'Prom.ua', 'rate' => 0.025],
            'aliexpress.com' => ['name' => 'AliExpress', 'rate' => 0.08],
        ],
    ],
    
    'salesdoubler' => [
        'enabled' => env('SALESDOUBLER_ENABLED', false),
        'api_key' => env('SALESDOUBLER_API_KEY'),
        'stores' => [],
    ],
    
    'direct' => [
        'enabled' => env('DIRECT_AFFILIATE_ENABLED', false),
        'stores' => [],
    ],
];
```

### Джерела знижок: ТІЛЬКИ де є affiliate

```
Фаза 1 (старт): Admitad API/фід
  → Admitad має Product Feeds / Coupons API
  → Парсимо офери, купони, знижки прямо з Admitad
  → Кожен товар вже має affiliate URL
  → Магазини: Rozetka, Comfy, Allo, Епіцентр, AliExpress

Фаза 2: + SalesDoubler
  → Підключаємо другий провайдер
  → Розширюємо пул магазинів

Фаза 3: Парсинг магазинів напряму
  → ТІЛЬКИ якщо для цього магазину є affiliate провайдер
  → ParserInterface перед публікацією перевіряє:
    if (!$affiliateManager->hasAffiliateFor($deal->url)) → skip
```

---

## Архітектура проекту

```
app/
├── Console/Commands/
│   ├── PublishScheduledPosts.php    # Публікація запланованих постів
│   ├── GeneratePostContent.php     # AI генерація тексту + зображення
│   ├── ParseDeals.php              # Парсинг знижок
│   ├── ParseAdmitadFeeds.php       # Парсинг оферів з Admitad API
│   ├── DrawRaffle.php              # Розіграш переможця лотереї
│   ├── GenerateDailySchedule.php   # Генерація розкладу на день
│   └── TrackPostStats.php          # Збір статистики постів
├── Enums/
│   ├── PostType.php                # Типи постів (рубрики)
│   ├── PostStatus.php              # draft, scheduled, published, failed
│   ├── DealCategory.php            # Категорії товарів
│   ├── DealSource.php              # admitad_feed, admitad_coupon, parser, manual
│   └── RaffleStatus.php            # Статуси розіграшів
├── Filament/
│   ├── Resources/
│   │   ├── PostResource.php        # CRUD постів
│   │   ├── DealResource.php        # CRUD знижок/товарів
│   │   ├── TemplateResource.php    # Шаблони постів
│   │   ├── RaffleResource.php      # Розіграші
│   │   ├── PromoCodeResource.php   # Промокоди
│   │   └── AffiliateStoreResource.php # Магазини та комісії
│   ├── Pages/
│   │   ├── Dashboard.php           # Головна: статистика, черга постів
│   │   ├── PostCalendar.php        # Календар запланованих постів
│   │   ├── GeneratePost.php        # Генерація поста через AI
│   │   ├── ChannelStats.php        # Аналітика каналу
│   │   └── Settings.php            # Налаштування (schedule, AI, TG, affiliate)
│   └── Widgets/
│       ├── UpcomingPostsWidget.php  # Наступні 5 постів
│       ├── StatsOverview.php        # Кліки, підписники, дохід
│       └── TopDealsWidget.php       # Найпопулярніші знижки
├── Jobs/
│   ├── GeneratePostTextJob.php      # AI генерація тексту
│   ├── GeneratePostImageJob.php     # AI генерація / composing зображення
│   ├── PublishToTelegramJob.php     # Публікація в TG канал
│   ├── ParseAdmitadFeedJob.php      # Парсинг Admitad фіду
│   ├── ScoreDealJob.php             # AI оцінка знижки
│   ├── TrackPostStatsJob.php        # Збір статистики поста
│   └── ProcessRaffleJob.php         # Обробка розіграшу
├── Models/
│   ├── Post.php                     # Пост в каналі
│   ├── Deal.php                     # Знижка/товар
│   ├── Template.php                 # Шаблон поста
│   ├── Raffle.php                   # Розіграш
│   ├── RaffleParticipant.php        # Учасник розіграшу
│   ├── PromoCode.php                # Промокод
│   ├── AffiliateStore.php           # Магазин + провайдер + комісія
│   ├── AffiliateClick.php           # Трекінг кліків
│   ├── Setting.php                  # Глобальні налаштування
│   ├── PostStat.php                 # Статистика поста
│   └── User.php                     # Адмін (Filament auth)
├── Services/
│   ├── AI/
│   │   ├── PostTextGenerator.php    # Генерація тексту поста
│   │   ├── ImageGenerator.php       # Генерація зображення
│   │   ├── DealImageComposer.php    # Реальне фото + PHP overlay
│   │   ├── ImagePromptBuilder.php   # Побудова промпта для зображення
│   │   └── DealScorer.php           # AI оцінка якості знижки
│   ├── Telegram/
│   │   ├── ChannelPublisher.php     # Публікація в канал
│   │   ├── PostFormatter.php        # Форматування поста для TG
│   │   ├── ReactionTracker.php      # Трекінг реакцій
│   │   └── InlineKeyboardBuilder.php # Побудова кнопок
│   ├── Affiliate/
│   │   ├── AffiliateProviderInterface.php
│   │   ├── AffiliateManager.php     # Центральний сервіс
│   │   ├── Providers/
│   │   │   ├── AdmitadProvider.php
│   │   │   ├── SalesDoublerProvider.php   # (заглушка для фази 2)
│   │   │   └── DirectAffiliateProvider.php # (заглушка для фази 3)
│   │   └── AdmitadFeedParser.php    # Парсинг фідів/купонів з Admitad API
│   ├── Parsers/                     # (фаза 3 — парсинг магазинів напряму)
│   │   ├── ParserInterface.php
│   │   ├── RozetkaParser.php
│   │   └── ...
│   └── Scheduling/
│       ├── PostScheduler.php        # Розподіл постів по часу з рандомом
│       └── RubricRotator.php        # Ротація рубрик протягом дня
└── Support/
    ├── PostTypeConfig.php
    └── TimeRandomizer.php
```

---

## База даних

### Таблиця `posts` — головна

```php
Schema::create('posts', function (Blueprint $table) {
    $table->id();
    $table->string('type');                    // PostType enum
    $table->string('status')->default('draft'); // draft, generating, ready, scheduled, publishing, published, failed
    
    // Контент
    $table->string('title', 500);
    $table->text('body');                      // Тіло поста (TG Markdown)
    $table->string('image_path')->nullable();  // Шлях до зображення
    $table->string('image_prompt')->nullable(); // Промпт яким генерили
    $table->json('inline_keyboard')->nullable();
    
    // Зв'язки
    $table->foreignId('deal_id')->nullable()->constrained();
    $table->foreignId('template_id')->nullable()->constrained();
    $table->foreignId('raffle_id')->nullable()->constrained('raffles');
    
    // Affiliate
    $table->string('affiliate_url', 1000)->nullable();
    $table->string('affiliate_provider')->nullable();  // admitad, salesdoubler, etc.
    
    // Планування
    $table->timestamp('scheduled_at')->nullable();
    $table->timestamp('published_at')->nullable();
    $table->bigInteger('telegram_message_id')->nullable();
    
    // Статистика
    $table->integer('views')->default(0);
    $table->integer('reactions_fire')->default(0);
    $table->integer('reactions_poop')->default(0);
    $table->integer('reactions_shock')->default(0);
    $table->integer('reactions_heart')->default(0);
    $table->integer('clicks')->default(0);
    $table->integer('forwards')->default(0);
    
    // Мета
    $table->json('meta')->nullable();
    $table->text('generation_log')->nullable();
    
    $table->timestamps();
    $table->softDeletes();
    
    $table->index(['status', 'scheduled_at']);
    $table->index('type');
});
```

### Таблиця `deals`

```php
Schema::create('deals', function (Blueprint $table) {
    $table->id();
    $table->string('title', 500);
    $table->text('description')->nullable();
    $table->decimal('price', 10, 2);
    $table->decimal('old_price', 10, 2)->nullable();
    $table->integer('discount_percent')->nullable();
    $table->string('url', 1000);                     // Оригінальне посилання
    $table->string('image_url', 1000)->nullable();   // Фото товару з магазину
    $table->string('brand', 100)->nullable();
    $table->string('category', 50);                  // DealCategory enum
    
    // Магазин
    $table->string('store_name', 100);               // Rozetka, Comfy...
    $table->string('store_domain', 100);             // rozetka.com.ua
    
    // Affiliate
    $table->string('affiliate_url', 1000)->nullable();
    $table->string('affiliate_provider')->nullable();
    $table->decimal('commission_rate', 5, 4)->nullable(); // 0.0350 = 3.5%
    
    // Джерело
    $table->string('source', 50);                    // DealSource enum: admitad_feed, admitad_coupon, parser, manual
    $table->string('external_id')->nullable();       // ID в системі джерела
    
    // AI
    $table->tinyInteger('ai_score')->nullable();
    $table->string('ai_verdict', 500)->nullable();
    $table->boolean('ai_is_fake')->nullable();
    
    // Стан
    $table->string('promo_code', 100)->nullable();
    $table->boolean('is_used')->default(false);
    $table->json('price_history')->nullable();
    $table->json('competitors')->nullable();         // Ціни в інших магазинах
    $table->timestamp('expires_at')->nullable();
    $table->timestamps();
    
    $table->index(['store_domain', 'category']);
    $table->index('ai_score');
    $table->index('is_used');
    $table->index('source');
    $table->unique(['url', 'source']);               // Не дублювати
});
```

### Таблиця `affiliate_stores` — довідник магазинів

```php
Schema::create('affiliate_stores', function (Blueprint $table) {
    $table->id();
    $table->string('name');                    // Rozetka
    $table->string('domain');                  // rozetka.com.ua
    $table->string('logo_path')->nullable();
    
    // Affiliate
    $table->string('affiliate_provider');      // admitad, salesdoubler, direct
    $table->string('affiliate_program_id')->nullable(); // ID програми в Admitad
    $table->decimal('commission_rate', 5, 4);  // 0.0350
    $table->string('commission_type')->default('cps'); // cps, cpl, cpa
    $table->integer('cookie_days')->default(30);
    
    // Статус
    $table->boolean('is_active')->default(true);
    $table->boolean('has_product_feed')->default(false);
    $table->boolean('has_coupon_feed')->default(false);
    $table->string('feed_url')->nullable();
    
    // Мета
    $table->json('categories')->nullable();    // Які категорії є в цьому магазині
    $table->text('notes')->nullable();
    $table->timestamps();
    
    $table->unique(['domain', 'affiliate_provider']);
});
```

### Таблиця `affiliate_clicks` — трекінг кліків

```php
Schema::create('affiliate_clicks', function (Blueprint $table) {
    $table->id();
    $table->foreignId('post_id')->constrained();
    $table->foreignId('deal_id')->nullable()->constrained();
    $table->string('affiliate_provider');
    $table->string('store_domain');
    $table->string('affiliate_url', 1000);
    $table->timestamp('clicked_at');
    $table->string('source')->default('channel'); // channel, bot
    $table->timestamps();
    
    $table->index(['post_id', 'clicked_at']);
    $table->index(['affiliate_provider', 'clicked_at']);
});
```

### Таблиця `templates`

```php
Schema::create('templates', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('type');                    // PostType enum
    $table->text('text_prompt');               // Промпт для GPT
    $table->text('image_prompt_template');     // Шаблон промпта для зображення
    $table->text('example_output')->nullable();
    $table->json('variables');                 // [{name, description, required}]
    $table->json('inline_keyboard_template')->nullable();
    $table->string('tone')->default('casual');
    $table->boolean('is_active')->default(true);
    $table->integer('priority')->default(0);
    $table->timestamps();
});
```

### Таблиця `raffles`

```php
Schema::create('raffles', function (Blueprint $table) {
    $table->id();
    $table->string('title');
    $table->text('description');
    $table->string('prize_description');
    $table->string('prize_image_path')->nullable();
    $table->string('participation_method');    // reaction, forward
    $table->string('required_reaction')->nullable();
    $table->timestamp('starts_at');
    $table->timestamp('ends_at');
    $table->bigInteger('announcement_message_id')->nullable();
    $table->bigInteger('result_message_id')->nullable();
    $table->string('winner_tg_username')->nullable();
    $table->bigInteger('winner_tg_id')->nullable();
    $table->string('status')->default('draft');
    $table->json('participants')->nullable();
    $table->integer('participants_count')->default(0);
    $table->timestamps();
});
```

### Таблиця `promo_codes`

```php
Schema::create('promo_codes', function (Blueprint $table) {
    $table->id();
    $table->string('code', 50);
    $table->string('store_name', 100);
    $table->string('store_domain', 100);
    $table->string('description');
    $table->string('discount_type');           // percent, fixed, free_shipping
    $table->decimal('discount_value', 10, 2)->nullable();
    $table->timestamp('valid_from')->nullable();
    $table->timestamp('valid_until')->nullable();
    $table->boolean('is_verified')->default(false);
    $table->boolean('is_used_in_post')->default(false);
    $table->string('source')->default('manual'); // manual, admitad, parsed
    $table->string('affiliate_provider')->nullable();
    $table->timestamps();
});
```

### Таблиця `settings`

```php
Schema::create('settings', function (Blueprint $table) {
    $table->id();
    $table->string('key')->unique();
    $table->text('value')->nullable();
    $table->string('type')->default('string');
    $table->string('group')->default('general');
    $table->string('description')->nullable();
    $table->timestamps();
});
```

**Дефолтні settings:**
```php
// Група: schedule
'posting_interval_hours' => 3,
'posting_randomize_minutes' => 25,       // ± рандом
'posting_quiet_start' => '23:00',
'posting_quiet_end' => '08:00',
'posts_per_day_target' => 6,
'rubric_rotation' => true,

// Група: ai
'openai_model_text' => 'gpt-4o-mini',
'openai_model_scoring' => 'gpt-4o-mini',
'image_provider' => 'dall-e-3',
'image_style' => 'vivid',
'image_size' => '1024x1024',
'text_language' => 'uk',
'text_tone' => 'casual-fun',

// Група: telegram
'channel_id' => '@zlovyv',
'bot_channel_name' => 'HotUA — Лови гарячі знижки!',
'reactions_enabled' => ['🔥', '💩', '😱', '❤️', '🏆'],

// Група: affiliate
'default_provider' => 'admitad',
'min_commission_rate' => 0.02,           // Мінімум 2% комісії щоб постити
```

### Таблиця `post_stats`

```php
Schema::create('post_stats', function (Blueprint $table) {
    $table->id();
    $table->foreignId('post_id')->constrained()->cascadeOnDelete();
    $table->date('date');
    $table->integer('views')->default(0);
    $table->integer('forwards')->default(0);
    $table->integer('clicks')->default(0);
    $table->json('reactions')->nullable();
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
    case DEAL = 'deal';                    // 🏷 Знижка
    case BATTLE = 'battle';                // ⚔️ Битва цін
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

## Шаблони постів — GPT промпти

### Глобальна system-інструкція для ВСІХ шаблонів

```
Ти — копірайтер українського TG каналу "HotUA — Лови гарячі знижки!" (@zlovyv).

ПРАВИЛА:
- Пиши ТІЛЬКИ УКРАЇНСЬКОЮ
- Тон: дружній, трохи жартівливий, корисний, НЕ нав'язливий
- НЕ використовуй: "спішіть", "тільки сьогодні", "не пропустіть" (банальщина)
- Emoji помірно: 5-8 на пост, не перебір
- Ніколи не пиши що ти AI
- Ватермарк "@zlovyv" в кінці кожного поста
- Кожен пост закінчується закликом до реакції або шерингу
- Ціни завжди в гривнях (₴)
```

### 1. DEAL — Знижка

**Text prompt:**
```
Створи пост про знижку для TG каналу @zlovyv.

Дані:
- Товар: {{product_title}}
- Бренд: {{brand}}
- Магазин: {{store_name}}
- Ціна зараз: {{price}}₴
- Стара ціна: {{old_price}}₴
- Знижка: {{discount_percent}}%
- AI Score: {{ai_score}}/10
- AI вердикт: {{ai_verdict}}
- Промокод: {{promo_code}} (якщо є)

Формат:
1. Перший рядок — емодзі + "ЗНИЖКА" + магазин
2. Назва товару жирним
3. Ціна: зачеркнута стара → нова великим
4. 1-2 речення чому це вигідно (з гумором або FOMO)
5. AI Score та вердикт
6. CTA кнопка (юзер побачить inline)
7. Останній рядок: "🔥 — беру! | 💩 — не варто"
8. 800-1200 символів

Відповідь — ТІЛЬКИ текст поста.
```

**Image prompt template:**
```
Product showcase photo for Telegram channel.
Product: {{product_title}} by {{brand}}.
Clean modern product photography on subtle gradient background.
Actual product prominently centered.
Bold red price label "{{price}}₴" bottom right.
Crossed-out old price "{{old_price}}₴" smaller above it.
"−{{discount_percent}}%" badge top right in yellow/red.
Store logo "{{store_name}}" subtle in corner.
"@zlovyv" watermark small at bottom.
Professional commercial quality, photorealistic.
No extra text. Square 1:1 aspect ratio.
```

**Inline keyboard:**
```json
[
    [{"text": "🛒 Купити за {{price}}₴", "url": "{{affiliate_url}}"}]
]
```

### 2. BATTLE — Битва цін

**Text prompt:**
```
Створи пост "Битва цін" для @zlovyv — порівняння товару в різних магазинах.

Дані:
- Товар: {{product_title}}
- Бренд: {{brand}}
- Ціни: {{prices_json}} (формат: [{"store": "Rozetka", "price": 5499}, ...])
- Переможець: {{cheapest_store}} ({{cheapest_price}}₴)
- Різниця: {{price_diff}}₴

Формат:
1. "⚔️ БИТВА ЦІН"
2. Назва товару
3. Таблиця цін: 🏆 для найдешевшого
4. "Різниця {{price_diff}}₴ — ось чому порівнюємо!"
5. CTA на найдешевший
6. "Скинь другу який збирався купити! 👆"
7. 600-900 символів

Відповідь — ТІЛЬКИ текст поста.
```

### 3. FAKE_OR_REAL — Фейк чи реал?

**Text prompt:**
```
Створи розслідування фейкової знижки для @zlovyv.

Дані:
- Товар: {{product_title}}
- Магазин: {{store_name}}
- Заявлена знижка: {{claimed_discount}}%
- Заявлена стара ціна: {{claimed_old_price}}₴
- Реальні ціни: тиждень тому {{real_price_week}}₴, місяць тому {{real_price_month}}₴, 3 місяці {{real_price_3m}}₴
- Поточна ціна: {{current_price}}₴
- Реальна знижка: {{real_discount}}%

Формат:
1. "🕵️ ФЕЙК ЧИ РЕАЛ?"
2. Що каже магазин vs реальність
3. Графік цін текстом
4. Вердикт: 🟢 РЕАЛ / 🟡 МАРКЕТИНГ / 🔴 ФЕЙК
5. Висновок: купувати чи ні
6. "Тегни друга який ведеться на 'знижки' 😄"
7. 800-1200 символів, тон розслідувальний

Відповідь — ТІЛЬКИ текст поста.
```

### 4. ANOMALY — Аномальна ціна

**Text prompt:**
```
Створи ТЕРМІНОВИЙ пост про аномально низьку ціну для @zlovyv.

Дані:
- Товар: {{product_title}}
- Магазин: {{store_name}}
- Поточна ціна: {{price}}₴
- Звичайна ціна: {{normal_price}}₴
- Знижка: {{discount_percent}}%

Формат:
1. "🚨 АНОМАЛІЯ | −{{discount_percent}}%"
2. FOMO: може зникнути, можливо помилка ціни
3. Порівняння цін
4. Терміновий CTA
5. "Кинь другу ЗАРАЗ — може зникнути ⏰"
6. 500-800 символів, тон збуджений але не спамний

Відповідь — ТІЛЬКИ текст поста.
```

### 5. TOP3 — Топ-3 дня

**Text prompt:**
```
Створи пост "Топ-3 знижки дня" для @zlovyv.

Дані:
- Deal 1: {{deal1_title}} — {{deal1_price}}₴ (було {{deal1_old_price}}₴) — {{deal1_store}}
- Deal 2: {{deal2_title}} — {{deal2_price}}₴ (було {{deal2_old_price}}₴) — {{deal2_store}}
- Deal 3: {{deal3_title}} — {{deal3_price}}₴ (було {{deal3_old_price}}₴) — {{deal3_store}}
- Дата: {{date}}

Формат:
1. "🔥 ТОП-3 ДНЯ | {{date}}"
2. Кожна угода: 🥇🥈🥉 + чому в топі
3. "Сьогодні можна зекономити до {{total_savings}}₴"
4. "Голосуй! 🔥=1, ❤️=2, 😱=3"
5. 800-1200 символів

Відповідь — ТІЛЬКИ текст поста.
```

### 6. RAFFLE — Розіграш

**Text prompt:**
```
Створи пост-розіграш для @zlovyv.

Дані:
- Приз: {{prize_description}}
- Вартість: {{prize_value}}₴
- Закінчення: {{ends_at}}
- Умова: {{participation_method}}

Формат:
1. "🎲 РОЗІГРАШ"
2. Акцент на приз
3. Прості умови (макс 3 кроки)
4. Дедлайн ВЕЛИКИМИ
5. "Шанси вищі якщо друг теж підписаний! 📤"
6. 600-900 символів, тон святковий

Відповідь — ТІЛЬКИ текст поста.
```

### 7. PROMO_CODE — Промокод

**Text prompt:**
```
Створи пост з промокодом для @zlovyv.

Дані:
- Код: {{code}}
- Магазин: {{store_name}}
- Знижка: {{discount_description}}
- Дійсний до: {{valid_until}}

Формат:
1. "🎟 ПРОМОКОД | {{store_name}}"
2. Код ВЕЛИКИМИ: 「{{code}}」
3. Що дає і де
4. Дедлайн
5. "Збережи і скинь кому треба 👆"
6. 400-600 символів

Відповідь — ТІЛЬКИ текст поста.
```

### 8. LIFEHACK — Лайфхак

**Text prompt:**
```
Створи корисний лайфхак для покупців, канал @zlovyv.

Тема: {{topic}}
Контекст: {{context}}

Можливі теми:
- Як повернути різницю якщо ціна впала
- Трюк з кошиком — залиште товар і отримайте знижку
- Коли найкращий час купувати техніку
- Як отримати безкоштовну доставку

Формат:
1. "💡 ЛАЙФХАК"
2. Реально працюючий трюк
3. Покрокова інструкція
4. "Зберігай 🔖 і скидай друзям!"
5. 600-1000 символів, тон "секрет який магазини не хочуть щоб ти знав"

Відповідь — ТІЛЬКИ текст поста.
```

### 9. SCAM_RATING — Рейтинг фейків

**Text prompt:**
```
Створи рейтинг магазинів за чесністю знижок для @zlovyv.

Дані:
- Період: {{period}}
- Магазини: {{stores_data}} (JSON: [{"store": "X", "total": 200, "fake_pct": 22}])

Формат:
1. "🏴‍☠️ РЕЙТИНГ ЧЕСНОСТІ | {{period}}"
2. Від найбільшого шахрая до найчеснішого
3. Кожен: позиція + % фейків + коротка оцінка
4. "Ми перевірили {{total}} акцій"
5. "Згоден? 🔥 — так | 💩 — все не так!"
6. 800-1200 символів, тон журналістський

Відповідь — ТІЛЬКИ текст поста.
```

---

## AI Зображення — стратегія

### Комбінований підхід (НЕ все AI!)

| Тип поста | Метод зображення |
|-----------|-----------------|
| DEAL, ANOMALY | **Реальне фото товару** з магазину + PHP overlay (Intervention Image) |
| BATTLE | Реальне фото + PHP інфографіка порівняння |
| FAKE_OR_REAL | Реальне фото + PHP "детективний" overlay |
| TOP3 | PHP колаж з 3 реальних фото |
| RAFFLE | **DALL-E 3** генерація (яскраве святкове) |
| PROMO_CODE | **PHP** — дизайн купон-картки |
| LIFEHACK | **DALL-E 3** ілюстрація |
| SCAM_RATING | **PHP** інфографіка з графіком |

### DealImageComposer (PHP — головний)

```php
// Services/AI/DealImageComposer.php
// Intervention Image v3

class DealImageComposer
{
    public function compose(Deal $deal, string $overlayType = 'standard'): string
    {
        // 1. Скачати реальне фото товару з deal->image_url
        $productImage = Image::read(file_get_contents($deal->image_url));
        
        // 2. Canvas 1080x1080, брендовий фон HotUA
        $canvas = Image::canvas(1080, 1080);
        // Градієнт: темний (#1a1a2e → #16213e)
        
        // 3. Фото товару по центру (resize fit 700x700)
        $canvas->place($productImage->contain(700, 700), 'center', 0, -40);
        
        // 4. Бейдж знижки (правий верхній кут)
        // Червоний круг з "−XX%" білим текстом
        
        // 5. Цінова плашка (внизу)
        // Темна напівпрозора полоса
        // Зачеркнута стара ціна (сірий) + нова (білий великий)
        
        // 6. Лого магазину (лівий верхній)
        // З папки storage/logos/{store_domain}.png
        
        // 7. HotUA бренд (правий нижній)
        // "🔥 @zlovyv" маленьким
        
        // 8. Рамка відповідного кольору по типу:
        // DEAL = blue, ANOMALY = red, BATTLE = orange
        
        $path = "posts/" . date('Y/m') . "/post_{$deal->id}_" . time() . '.jpg';
        Storage::disk('public')->put($path, $canvas->toJpeg(90));
        return $path;
    }
    
    public function composeBattle(Deal $mainDeal, array $competitors): string
    {
        // Фото товару зверху
        // Під ним — горизонтальні бари з цінами по магазинах
        // Зелений бар = найдешевший з 🏆
    }
    
    public function composeTop3(array $deals): string
    {
        // Три фото в ряд (1080x1080 сітка 3 колонки)
        // 🥇🥈🥉 під кожним
        // Ціна великим під фото
    }
    
    public function composePromoCard(PromoCode $promo): string
    {
        // Стиль подарункового купона
        // Код ВЕЛИКИМИ по центру в рамці
        // Магазин, знижка, дедлайн
    }
}
```

### ImageGenerator (DALL-E 3 — тільки для абстрактних)

```php
class ImageGenerator
{
    public function generate(string $prompt, string $size = '1024x1024'): string
    {
        // OpenAI DALL-E 3 API
        $response = OpenAI::images()->create([
            'model' => 'dall-e-3',
            'prompt' => $prompt,
            'n' => 1,
            'size' => $size,
            'quality' => 'standard',
            'style' => 'vivid',
        ]);
        
        $imageUrl = $response->data[0]->url;
        
        // Скачати і зберегти локально
        $path = "posts/" . date('Y/m') . "/ai_" . time() . '.png';
        Storage::disk('public')->put($path, file_get_contents($imageUrl));
        return $path;
    }
}
```

**Ключовий принцип: реальні фото товарів + дизайнерський overlay = виглядає професійно і НЕ аішно.**

---

## Розклад + рандомізація

### PostScheduler

```php
class PostScheduler
{
    public function generateDailySchedule(Carbon $date): array
    {
        $interval = (int) Setting::get('posting_interval_hours', 3);
        $randomize = (int) Setting::get('posting_randomize_minutes', 25);
        $quietStart = Setting::get('posting_quiet_start', '23:00');
        $quietEnd = Setting::get('posting_quiet_end', '08:00');
        $target = (int) Setting::get('posts_per_day_target', 6);
        
        $slots = [];
        $current = $date->copy()->setTimeFromTimeString($quietEnd);
        $end = $date->copy()->setTimeFromTimeString($quietStart);
        
        while ($current->lt($end) && count($slots) < $target) {
            $offset = rand(-$randomize, $randomize);
            $publishAt = $current->copy()->addMinutes($offset);
            
            if ($publishAt->between(
                $date->copy()->setTimeFromTimeString($quietEnd),
                $end
            )) {
                $slots[] = $publishAt;
            }
            
            $current->addHours($interval);
        }
        
        return $slots;
    }
}
```

### RubricRotator — ротація рубрик по днях

```
Щодня:        DEAL, BATTLE, FAKE_OR_REAL, TOP3, PROMO_CODE, DEAL
Понеділок:    ... замість FAKE → SCAM_RATING (місячний)
Середа:       ... замість PROMO → LIFEHACK
П'ятниця:     ... замість останнього DEAL → RAFFLE
Неділя:       ... замість TOP3 → WEEKLY_DIGEST

Приклад вівторок:
  ~08:17 🏷 Знижка
  ~11:03 ⚔️ Битва цін
  ~14:22 🕵️ Фейк чи реал?
  ~17:41 🔥 Топ-3 дня
  ~20:08 🎟 Промокод
  ~22:35 🏷 Знижка
```

---

## Адмінка (Filament 3)

### Dashboard

```
┌─────────────────────────────────────────────────┐
│  🔥 HotUA Admin                                 │
├──────────┬──────────┬──────────┬────────────────┤
│ Підписн. │ Перегл.  │ Кліки    │ Дохід (est.)  │
│ 2,847    │ 45.2k    │ 1,203    │ ~18,500₴      │
├──────────┴──────────┴──────────┴────────────────┤
│                                                  │
│  ⏰ Наступні пости:                              │
│  14:22 🕵️ Фейк чи реал? — Samsung S24   [✏️]  │
│  17:41 🔥 Топ-3 дня — [генерується...⏳]       │
│  20:08 🎟 Промокод — Rozetka -15%        [✅]  │
│  22:35 🏷 Знижка — JBL Tune 520BT       [✅]  │
│                                                  │
│  📅 Календар [→]     🔥 Топ пости тижня         │
│                      1. Аномалія JBL (23k 👁)    │
│                      2. Битва AirPods (18k)      │
└──────────────────────────────────────────────────┘
```

### Сторінки

1. **Пости** — список, фільтри, створення, кнопка "🤖 Згенерувати AI", preview
2. **Знижки** — знайдені deals, AI Score, "Створити пост з цієї знижки"
3. **Шаблони** — CRUD промптів з {{змінними}}, тестова генерація
4. **Розіграші** — CRUD, учасники, "Визначити переможця"
5. **Промокоди** — CRUD, статус, прив'язка до магазину
6. **Магазини** — affiliate stores довідник, комісії, статус
7. **Календар** — візуальний тижневий/місячний, drag&drop
8. **Аналітика** — графіки підписників, переглядів, кліків, дохід
9. **Налаштування** — schedule, AI, TG, affiliate

### Сторінка "Згенерувати пост"

```
1. Обрати рубрику (dropdown PostType)
2. Обрати шаблон (або авто по рубриці)
3. Обрати знижку (Deal) — або створити вручну
4. [Заповнити змінні автоматично з Deal]
5. Натиснути [🤖 Згенерувати текст]
6. Побачити preview тексту → відредагувати якщо треба
7. Натиснути [🎨 Згенерувати зображення]
8. Побачити preview зображення
9. Обрати час публікації (або "наступний слот")
10. [Зберегти як чернетку] або [Запланувати]
```

---

## Pipeline генерації поста (повний цикл)

```
1. Scheduler: "14:22 потрібен пост FAKE_OR_REAL"

2. GeneratePostContent job:
   a. Знайти Deal з ai_score + price_history (де is_used=false)
   b. Перевірити: $affiliateManager->hasAffiliateFor($deal->url) — інакше skip
   c. Обрати Template для FAKE_OR_REAL
   d. Підставити змінні
   e. GPT-4o-mini → текст поста
   f. DealImageComposer → реальне фото + детективний overlay
   g. AffiliateManager → згенерити affiliate URL
   h. Зібрати inline keyboard
   i. Зберегти Post(status=ready, scheduled_at=14:22)

3. PublishToTelegramJob (о 14:22):
   a. Bot::sendPhoto(channelId, photo, caption, replyMarkup)
   b. Зберегти telegram_message_id
   c. Post.status = published
   d. Deal.is_used = true

4. TrackPostStatsJob (кожну годину):
   a. Для published постів за 7 днів
   b. TG Bot API → views, forwards
   c. Зберегти в post_stats
```

---

## Вірусні механіки

### В кожному шаблоні — CTA для шерингу:

```
DEAL:        "🔥 — беру! | 💩 — не варто"
BATTLE:      "Скинь другу який збирався купити! 👆"
FAKE_REAL:   "Тегни друга який ведеться на 'знижки' 😄"
ANOMALY:     "Кинь другу ЗАРАЗ — може зникнути ⏰"
TOP3:        "Голосуй! 🔥=1, ❤️=2, 😱=3"
RAFFLE:      "Шанси вищі якщо друг теж підписаний! 📤"
PROMO_CODE:  "Збережи і скинь кому треба 👆"
LIFEHACK:    "Зберігай 🔖 і скидай друзям!"
SCAM_RATING: "Згоден? 🔥 — так | 💩 — все не так!"
```

### Нумерація серій: "🕵️ ФЕЙК ЧИ РЕАЛ? #47", "⚔️ БИТВА ЦІН #23"
### User mentions в розіграшах: "@username виграв!" → друзі бачать → підписуються
### Scam Rating = гарантована вірусність (контроверсія → дискусія → шери)

---

## .env

```
APP_NAME=HotUA
APP_URL=https://admin.hotua.com

TELEGRAM_BOT_TOKEN=xxx
TELEGRAM_CHANNEL_ID=@zlovyv

OPENAI_API_KEY=xxx
OPENAI_MODEL=gpt-4o-mini
OPENAI_IMAGE_MODEL=dall-e-3

REPLICATE_API_TOKEN=xxx

# Affiliate — Admitad (старт)
ADMITAD_ENABLED=true
ADMITAD_CAMPAIGN_ID=xxx
ADMITAD_API_KEY=xxx
ADMITAD_API_SECRET=xxx

# Affiliate — SalesDoubler (фаза 2)
SALESDOUBLER_ENABLED=false
SALESDOUBLER_API_KEY=

# Affiliate — Direct (фаза 3)
DIRECT_AFFILIATE_ENABLED=false

ADMIN_EMAIL=admin@hotua.com
ADMIN_PASSWORD=xxx

DB_CONNECTION=mysql
DB_DATABASE=hotua
REDIS_HOST=redis
QUEUE_CONNECTION=redis
CACHE_STORE=redis
```

---

## Порядок реалізації

### Фаза 1 (Тиждень 1): Ядро + Адмінка

1. Laravel 12 + Filament 3 + Nutgram + Intervention Image setup
2. Всі міграції
3. Моделі з relationships
4. Enums (PostType, PostStatus, DealCategory, DealSource)
5. Setting model + seeder з дефолтами
6. Filament auth + Dashboard
7. PostResource, DealResource, TemplateResource — CRUD
8. Template seeder: всі 9 шаблонів з промптами

### Фаза 2 (Тиждень 1-2): AI + Affiliate

9. AffiliateProviderInterface + AffiliateManager + AdmitadProvider
10. AffiliateStoreResource (довідник магазинів) + seeder
11. AdmitadFeedParser (парсинг оферів/купонів з Admitad)
12. DealScorer (GPT оцінка знижок)
13. PostTextGenerator (GPT генерація тексту по шаблонах)
14. DealImageComposer (реальне фото + PHP overlay)
15. ImageGenerator (DALL-E 3 для абстрактних)
16. Сторінка "Згенерувати пост" в адмінці

### Фаза 3 (Тиждень 2): Автоматизація

17. PostScheduler + TimeRandomizer + RubricRotator
18. GenerateDailySchedule command
19. PublishToTelegramJob + ChannelPublisher
20. PostCalendar page в адмінці
21. Scheduler cron: авто-генерація + авто-публікація

### Фаза 4 (Тиждень 3): Engagement + Аналітика

22. RaffleResource + ProcessRaffleJob
23. PromoCodeResource
24. TrackPostStatsJob + ChannelStats page
25. StatsOverview widget на dashboard
26. Settings page (всі налаштування)

### Фіча-backlog (коли канал росте):
- SalesDoublerProvider (другий affiliate провайдер)
- Парсери магазинів напряму (Rozetka, Comfy, Allo) — тільки якщо є affiliate
- DirectAffiliateProvider (прямі партнерки)
- Бот @zlovyv_bot (персоналізація, Фаза 2 із окремим ТЗ)

---

## КРИТИЧНІ ВИМОГИ

1. **PHP 8.4** — використовуй сучасний синтаксис (property hooks, readonly classes, enums, match, named args)
2. **Affiliate-first** — не постити товар без affiliate URL. AffiliateManager перевіряє ПЕРЕД публікацією
3. **Multi-provider affiliate** — інтерфейс + реалізації, легко додати нового провайдера
4. **Зображення товарів = реальні фото + PHP overlay**, AI тільки для абстрактних
5. **Всі тексти УКРАЇНСЬКОЮ**
6. **Рандомізація часу ±25 хв**, тиша 23:00-08:00
7. **Шаблони в БД** — не хардкодити промпти, редагуються в адмінці
8. **Preview перед публікацією**
9. **Affiliate лінк в КОЖНОМУ пості** де є товар
10. **Filament 3** для адмінки
11. **Queue** для всього важкого (AI, парсинг, публікація)
12. **Канал @zlovyv**, бренд "HotUA — Лови гарячі знижки!"

Почни з Фази 1: Laravel + Filament + міграції + моделі + enums + базова адмінка + seed шаблонів.
