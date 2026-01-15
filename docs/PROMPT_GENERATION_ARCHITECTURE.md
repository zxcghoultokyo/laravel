# 🧠 Prompt Generation Architecture

## Поточний стан: Як стакаються компоненти

```
┌─────────────────────────────────────────────────────────────────────────────────┐
│                            SYSTEM PROMPT STACK                                   │
├─────────────────────────────────────────────────────────────────────────────────┤
│                                                                                  │
│  1. PROMPT PRESET (найвищий пріоритет, якщо матчить контекст)                   │
│     └── Повністю замінює дефолтний промпт                                       │
│     └── Підтримує змінні {{variable}}                                           │
│     └── Матчиться по: language, tone, campaign, categories                      │
│                                                                                  │
│  ↓ АБО (якщо немає матчу)                                                        │
│                                                                                  │
│  2. DEFAULT SYSTEM PROMPT (вбудований в агент)                                  │
│     ├── {{faq_info}} ← WidgetSettings (FAQ texts)                               │
│     ├── {{tone_section}} ← ToneService                                          │
│     │   ├── Tone prompt (official/spartan/friendly)                             │
│     │   └── Brand rules (до 5 правил)                                           │
│     └── {{price_context}} ← PriceStatsService                                   │
│                                                                                  │
├─────────────────────────────────────────────────────────────────────────────────┤
│                                                                                  │
│  GREETINGS (окремо від system prompt!)                                          │
│     └── Перше повідомлення при відкритті чату                                   │
│     └── НЕ впливає на system prompt                                             │
│     └── Матчиться по: UTM, категорія, device, мова, час доби                    │
│     └── Quick actions (кнопки)                                                  │
│                                                                                  │
└─────────────────────────────────────────────────────────────────────────────────┘
```

## Проблема поточної архітектури

1. **Prompt Presets** — ручне створення, не враховують дані магазину
2. **Tone** — тільки стиль (official/spartan/friendly), не контент
3. **Greetings** — окремі від промпту, тільки перше повідомлення
4. **FAQ Info** — завантажується з WidgetSettings, але статичний текст
5. **Немає онбоардингу** — не збираємо дані про магазин для генерації

## Запропонована архітектура: Auto-Generated Prompts

### Концепція

```
┌─────────────────────────────────────────────────────────────────────────────────┐
│                           ONBOARDING FLOW                                        │
├─────────────────────────────────────────────────────────────────────────────────┤
│                                                                                  │
│  1. Користувач підключає магазин (Horoshop/Shopify)                             │
│     └── Отримуємо: products, categories, FAQ pages                              │
│                                                                                  │
│  2. PromptGeneratorService аналізує дані:                                       │
│     ├── Категорії товарів → store_type (tactical, fashion, electronics...)     │
│     ├── Бренди → brand_list                                                     │
│     ├── Цінові діапазони → price_segments                                       │
│     ├── FAQ/Policies → knowledge_base                                           │
│     └── Кількість товарів → catalog_size                                        │
│                                                                                  │
│  3. Генеруємо базовий PromptPreset для магазину                                 │
│     └── Через GPT або шаблон на основі store_type                               │
│                                                                                  │
│  4. Користувач може:                                                            │
│     └── Редагувати промпт в /admin/prompts                                      │
│     └── Додавати умови (категорії, кампанії)                                    │
│     └── Тестувати в preview                                                     │
│                                                                                  │
└─────────────────────────────────────────────────────────────────────────────────┘
```

### Дані для генерації промпту

| Джерело | Дані | Вплив на промпт |
|---------|------|-----------------|
| **Products** | Категорії, бренди, ціни | Тип магазину, експертиза, цінові поради |
| **Categories** | Ієрархія категорій | Що продаємо, як структурувати відповіді |
| **FAQ Pages** | Доставка, оплата, повернення | Знання про магазин |
| **Brand info** | Назва, опис, контакти | Персоналізація, контакти |
| **Sales data** | Популярні товари | Рекомендації |
| **Analytics** | Часті питання | Оптимізація відповідей |

### Нова структура StoreContext

```php
// app/Models/StoreContext.php
class StoreContext extends Model
{
    protected $fillable = [
        'widget_settings_id',
        
        // Auto-detected
        'store_type',           // tactical, fashion, electronics, general
        'primary_categories',   // JSON: ["Плитоноски", "Шоломи", "Взуття"]
        'brands',               // JSON: ["Crye", "Ops-Core", "FirstSpear"]
        'price_segments',       // JSON: {budget: 2000, mid: 5000, premium: 15000}
        'catalog_size',         // small (<100), medium (<1000), large (>1000)
        
        // From FAQ/Policies
        'delivery_info',        // Parsed from FAQ
        'payment_info',
        'return_policy',
        'warranty_info',
        'working_hours',
        
        // For prompt
        'expertise_areas',      // JSON: ["бронезахист", "тактичне спорядження"]
        'common_questions',     // JSON: most asked questions
        'product_tips',         // JSON: ["плити мають відповідати розміру плитоноски"]
        
        // Generated
        'generated_prompt',     // Auto-generated system prompt
        'prompt_version',       // For updates
        'last_analyzed_at',
    ];
}
```

### PromptGeneratorService

```php
// app/Services/Ai/PromptGeneratorService.php
class PromptGeneratorService
{
    /**
     * Analyze store and generate optimal prompt.
     */
    public function generateForStore(int $widgetSettingsId): PromptPreset
    {
        // 1. Collect data
        $context = $this->collectStoreContext($widgetSettingsId);
        
        // 2. Detect store type
        $storeType = $this->detectStoreType($context['categories']);
        
        // 3. Generate prompt based on type
        $prompt = $this->generatePrompt($storeType, $context);
        
        // 4. Create PromptPreset
        return PromptPreset::create([
            'name' => "Auto: {$context['store_name']}",
            'slug' => 'auto-' . $widgetSettingsId,
            'system_prompt' => $prompt,
            'variables' => $this->extractVariables($context),
            'is_default' => true,
            'is_active' => true,
        ]);
    }
    
    private function collectStoreContext(int $id): array
    {
        $settings = WidgetSettings::find($id);
        
        return [
            'store_name' => $settings->store_name ?? $settings->bot_name,
            'categories' => Product::distinct('category_path')->pluck('category_path'),
            'brands' => Product::distinct('brand')->whereNotNull('brand')->pluck('brand'),
            'price_range' => [
                'min' => Product::min('price'),
                'max' => Product::max('price'),
                'avg' => Product::avg('price'),
            ],
            'product_count' => Product::count(),
            'faq' => [
                'delivery' => $settings->faq_payment_delivery_text,
                'returns' => $settings->faq_returns_text,
                'contacts' => $settings->faq_contacts_text,
                'about' => $settings->faq_about_text,
            ],
        ];
    }
    
    private function detectStoreType(Collection $categories): string
    {
        // Keyword matching для визначення типу магазину
        $keywords = [
            'tactical' => ['плитоноска', 'шолом', 'тактичн', 'броня', 'military'],
            'fashion' => ['одяг', 'взуття', 'куртка', 'штани', 'футболка'],
            'electronics' => ['електроніка', 'гаджет', 'телефон', 'ноутбук'],
            'sports' => ['спорт', 'фітнес', 'тренування', 'велосипед'],
        ];
        
        // ... matching logic
    }
}
```

### Prompt Templates по типу магазину

```php
// database/seeders/PromptTemplatesSeeder.php

// TACTICAL STORE
$tacticalTemplate = <<<PROMPT
Ти — AI-експерт магазину "{{shop_name}}" з тактичного та військового спорядження.

ТВОЯ ЕКСПЕРТИЗА:
- Плитоноски та бронежилети (сумісність плит і носіїв)
- Шоломи та захист голови (NIJ стандарти)
- Тактичне взуття та одяг
- Підсумки та РПС системи

АСОРТИМЕНТ:
{{categories_list}}

БРЕНДИ:
{{brands_list}}

ЦІНОВІ СЕГМЕНТИ:
- Бюджетний: до {{budget_max}} грн
- Середній: {{budget_max}}-{{mid_max}} грн  
- Преміум: від {{mid_max}} грн

{{faq_section}}

{{tone_section}}
PROMPT;

// FASHION STORE
$fashionTemplate = <<<PROMPT
Ти — AI-консультант магазину одягу "{{shop_name}}".

ТВОЯ ЕКСПЕРТИЗА:
- Підбір розмірів (таблиці розмірів, поради)
- Сезонні колекції
- Комплектування образів

КАТЕГОРІЇ:
{{categories_list}}

БРЕНДИ:
{{brands_list}}

{{faq_section}}

{{tone_section}}
PROMPT;
```

## Як все стакається (нова архітектура)

```
┌─────────────────────────────────────────────────────────────────────────────────┐
│                     ПОВНИЙ СТЕК ПЕРСОНАЛІЗАЦІЇ                                   │
├─────────────────────────────────────────────────────────────────────────────────┤
│                                                                                  │
│  ┌─────────────────────────────────────────────────────────────────────────┐    │
│  │ LAYER 1: GREETING (перше повідомлення)                                  │    │
│  │ ├── Показується при відкритті чату                                      │    │
│  │ ├── Quick Actions (кнопки)                                              │    │
│  │ ├── Матч по: UTM, URL, device, час, мова                                │    │
│  │ └── НЕ впливає на AI логіку                                             │    │
│  └─────────────────────────────────────────────────────────────────────────┘    │
│                                                                                  │
│  ┌─────────────────────────────────────────────────────────────────────────┐    │
│  │ LAYER 2: SYSTEM PROMPT (як AI думає)                                    │    │
│  │ ├── Генерується автоматично при онбоардингу                             │    │
│  │ ├── Включає: store context, expertise, FAQ, policies                    │    │
│  │ ├── Можна редагувати в /admin/prompts                                   │    │
│  │ └── Змінні: {{shop_name}}, {{categories}}, {{faq}}, {{tone}}            │    │
│  └─────────────────────────────────────────────────────────────────────────┘    │
│                                                                                  │
│  ┌─────────────────────────────────────────────────────────────────────────┐    │
│  │ LAYER 3: TONE (стиль відповідей)                                        │    │
│  │ ├── Вбудовується в system prompt як {{tone_section}}                    │    │
│  │ ├── Три режими: official, spartan, friendly                             │    │
│  │ └── Brand rules (до 5 правил)                                           │    │
│  └─────────────────────────────────────────────────────────────────────────┘    │
│                                                                                  │
│  ┌─────────────────────────────────────────────────────────────────────────┐    │
│  │ LAYER 4: DYNAMIC CONTEXT (runtime)                                      │    │
│  │ ├── Історія чату [Показані товари: ...]                                 │    │
│  │ ├── [КОНТЕКСТ РОЗМОВИ: плитоноски, розмір M]                            │    │
│  │ └── Session data (shown_ids, filters)                                   │    │
│  └─────────────────────────────────────────────────────────────────────────┘    │
│                                                                                  │
│  ┌─────────────────────────────────────────────────────────────────────────┐    │
│  │ LAYER 5: TOOLS RESULTS (інструменти)                                    │    │
│  │ ├── search_products() → товари з Meili/DB                               │    │
│  │ ├── get_product_details() → повна картка                                │    │
│  │ └── get_order_status() → статус замовлення                              │    │
│  └─────────────────────────────────────────────────────────────────────────┘    │
│                                                                                  │
└─────────────────────────────────────────────────────────────────────────────────┘
```

## Flow: Від онбоардингу до відповіді

```
1. ONBOARDING
   └── Користувач підключає магазин
       └── Sync products, categories, FAQ
           └── PromptGeneratorService.generateForStore()
               └── Створюється PromptPreset (is_default=true)

2. USER OPENS CHAT
   └── Widget loads greeting
       └── GreetingService.getForContext({utm, url, device})
           └── Показує перше повідомлення + quick actions

3. USER SENDS MESSAGE
   └── StreamingFunctionCallingAgent.stream()
       └── getSystemPrompt()
           ├── PromptPresetService.getSystemPromptForContext()
           │   └── Знаходить preset по language/tone/campaign/category
           │   └── Рендерить з variables: {{shop_name}}, {{faq}}, {{tone}}
           │
           └── АБО getDefaultSystemPrompt() якщо немає матчу
               └── Використовує ToneService + loadFaqInfo()

4. GPT RESPONDS
   └── З урахуванням system prompt + history + tool results
```

## TODO: Implementation Plan

### Phase 1: StoreContext Model ✅ DONE
- [x] Міграція для `store_contexts` таблиці
- [x] Model з relationships до WidgetSettings
- [x] Service для збору контексту з products/categories

### Phase 2: PromptGeneratorService ✅ DONE
- [x] Detect store type по категоріях
- [x] Template-based generation
- [x] 5 templates: tactical, fashion, electronics, sports, general
- [x] Variables system with FAQ integration
- [x] Integration with PromptPresetService

### Phase 3: Jobs & API ✅ DONE
- [x] AnalyzeStoreContextJob for async processing
- [x] Admin API endpoints (POST /api/admin/store-context/*)
- [x] Test script (test-prompt-generation.php)

### Phase 4: Onboarding Integration ⏳ TODO
- [ ] UI wizard для онбоардингу
- [ ] Auto-generate prompt на sync
- [ ] Preview та редагування

### Phase 5: Analytics-Driven Optimization ⏳ TODO
- [ ] Збір common questions
- [ ] Аналіз низьких conversion rates
- [ ] Suggestions для покращення промпту

## Приклад: Тактичний магазин

**Вхідні дані:**
```json
{
  "categories": ["Плитоноски", "Шоломи", "Берці", "Підсумки"],
  "brands": ["Crye Precision", "Ops-Core", "FirstSpear", "EastGear"],
  "products_count": 847,
  "price_range": {"min": 150, "max": 45000, "avg": 4500},
  "faq": {
    "delivery": "Нова Пошта, 1-3 дні. Безкоштовно від 2000 грн.",
    "returns": "14 днів обмін/повернення"
  }
}
```

**Згенерований промпт:**
```
Ти — AI-експерт магазину "Contractor" з тактичного спорядження.

ЕКСПЕРТИЗА:
- Плитоноски та бронежилети
- Шоломи та захист голови  
- Тактичне взуття (берці)
- Підсумки та РПС системи

БРЕНДИ В АСОРТИМЕНТІ:
Crye Precision, Ops-Core, FirstSpear, EastGear

ЦІНОВІ СЕГМЕНТИ (847 товарів):
- Бюджетний: до 1500 грн
- Середній: 1500-5000 грн
- Преміум: від 5000 грн (до 45000 грн)

ДОСТАВКА:
Нова Пошта, 1-3 дні. Безкоштовно від 2000 грн.

ПОВЕРНЕННЯ:
14 днів обмін/повернення

СТИЛЬ: Офіційний
- Звертайся на "Ви"
- Професійний тон
```

## Usage Examples

### CLI: Analyze and Generate

```bash
# Analyze store and create StoreContext
php artisan tinker --execute="
\$generator = app(\App\Services\Ai\PromptGeneratorService::class);
\$context = \$generator->analyzeStore(null);
echo 'Store type: ' . \$context->store_type;
"

# Generate prompt from context
php artisan tinker --execute="
\$generator = app(\App\Services\Ai\PromptGeneratorService::class);
\$context = \App\Models\StoreContext::latest()->first();
\$prompt = \$generator->generatePrompt(\$context);
echo \$prompt;
"

# Or use test script
php test-prompt-generation.php
```

### API: Admin Endpoints

```bash
# Analyze store (async)
curl -X POST "http://localhost:8000/api/admin/store-context/analyze?async=true" \
  -H "Authorization: Bearer YOUR_ADMIN_TOKEN"

# Get current context
curl "http://localhost:8000/api/admin/store-context" \
  -H "Authorization: Bearer YOUR_ADMIN_TOKEN"

# Generate prompt
curl -X POST "http://localhost:8000/api/admin/store-context/generate-prompt" \
  -H "Authorization: Bearer YOUR_ADMIN_TOKEN"

# Create PromptPreset from context
curl -X POST "http://localhost:8000/api/admin/store-context/create-preset" \
  -H "Authorization: Bearer YOUR_ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name": "My Auto Prompt"}'
```

### Dispatch Job

```php
use App\Jobs\AnalyzeStoreContextJob;

// Analyze and generate prompt
AnalyzeStoreContextJob::dispatch(
    widgetSettingsId: 1,
    generatePrompt: true
);
```

## Implementation Notes

### Files Created

- `database/migrations/2026_01_15_183523_create_store_contexts_table.php` - DB schema
- `app/Models/StoreContext.php` - Model with helpers
- `app/Services/Ai/PromptGeneratorService.php` - Core service (541 lines)
- `app/Jobs/AnalyzeStoreContextJob.php` - Async job
- `app/Http/Controllers/Api/Admin/StoreContextController.php` - API controller
- `test-prompt-generation.php` - Test script

### Integration Points

- `PromptPresetService::getBestPromptForContext()` - tries manual preset first, then auto-generated
- `PromptPresetService::getAutoGeneratedPrompt()` - loads from StoreContext
- `WidgetSettings::storeContext()` - relationship
- Routes: `api/admin/store-context/*` (token protected)

### Store Type Detection

Keywords matched against categories (case-insensitive):
- **Tactical**: плитоноска, шолом, тактичн, броня, military, берц, підсумок, molle
- **Fashion**: одяг, взуття, куртка, футболка, джинс, кросівк
- **Electronics**: електроніка, гаджет, телефон, ноутбук, навушник
- **Sports**: спорт, фітнес, тренування, велосипед, gym

Minimum 3 keyword matches required, otherwise defaults to `general`.

