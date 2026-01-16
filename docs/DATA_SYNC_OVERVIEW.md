# 🔄 Огляд синхронізації даних

> Повний список всіх механізмів імпорту, збагачення та індексації даних

## 📊 Короткий огляд

| Джерело | Призначення | Частота | Статус |
|---------|-------------|---------|--------|
| Horoshop API | `products` | Daily 03:00 | ✅ Scheduled |
| Horoshop API | `orders` | Daily 03:30 | ✅ Scheduled |
| OpenAI | `product_ai_index` | Daily 04:00 | ✅ Scheduled |
| `products` + AI | Meilisearch | Daily 05:00 | ✅ Scheduled |
| `order_items` | `products.orders_count` | Daily 06:00 | ✅ Scheduled |
| `products` | `categories` | Weekly (Sun 02:00) | ✅ Scheduled |
| OpenAI | `embeddings` | Weekly (Sun 02:30) | ✅ Scheduled |
| Config | Synonyms/Slang | Static | ✅ Config |

---

## 🖥️ Моніторинг (Super Admin)

Сторінка звітів синхронізації доступна для Super Admin:
- **URL:** `/admin/sync-reports`
- **Доступ:** тільки `stovburtm@gmail.com`

Показує:
- 📊 Статистику товарів (всього, в наявності, нові сьогодні)
- 🤖 Покриття AI індексом
- 🔍 Статус Meilisearch (online/offline, кількість документів)
- 📦 Статистику замовлень
- ⏰ Розклад синхронізацій з можливістю ручного запуску
- 📜 Історію синхронізацій з результатами

---

## 1️⃣ HOROSHOP → Товари

### Основні файли:
- **Job:** `app/Jobs/IncrementalProductSyncJob.php`
- **Service:** `app/Services/Horoshop/ProductService.php`
- **Client:** `app/Services/Horoshop/HoroshopClient.php`
- **Command:** `app/Console/Commands/SyncHoroshopProductsCommand.php`

### Що синхронізується:
```
Horoshop /api/products/ → products table
- article, parent_article, title
- category_path, brand, color, size
- price, price_old, in_stock, quantity
- raw (повний JSON від Horoshop)
- images, search_index
```

### Команди:
```bash
# Повна синхронізація (daily cron)
php artisan horoshop:sync

# Інкрементальна (тільки зміни)
php artisan products:incremental-sync

# З опціями
php artisan horoshop:sync --limit=100 --dry-run
```

### Поточний Schedule:
```php
// routes/console.php
Schedule::command('horoshop:sync-products')->daily()->at('03:00');
```

---

## 2️⃣ HOROSHOP → Замовлення

### Основні файли:
- **Job:** `app/Jobs/FetchHoroshopOrdersJob.php`
- **Command:** `app/Console/Commands/SyncOrdersCommand.php`

### Що синхронізується:
```
Horoshop /api/orders/get/ → orders + order_items tables
- order_id, status, total_sum
- delivery_name, delivery_phone, delivery_address
- products[] (title, article, price, quantity)
- analytics (utm_source, utm_campaign)
- Автоматична прив'язка до chat session
```

### Команди:
```bash
# Синхронізація за період
php artisan orders:sync --days=7

# За конкретні дати
php artisan orders:sync --from="2026-01-10" --to="2026-01-16"

# Тільки певний статус
php artisan orders:sync --status=delivered
```

### Тригер з widget:
```
checkout_success event → FetchHoroshopOrdersJob (delay 30s)
```

### ⚠️ Потрібно:
- [ ] Додати scheduled sync щодня/щогодини

---

## 3️⃣ AI Enrichment → Товари

### Основні файли:
- **Job:** `app/Jobs/EnrichProductWithAiJob.php`
- **Batch Job:** `app/Jobs/EnrichProductGroupJob.php`
- **Service:** `app/Services/Ai/ProductEnrichmentService.php`
- **Command:** `app/Console/Commands/BuildAiIndexCommand.php`

### Що генерується:
```
OpenAI GPT → product_ai_index table
- ai_product_type (тактичний жилет, підсумок...)
- ai_category (plate_carriers, pouches...)
- keywords[] (NIJ IV, multicam, molle...)
- slang[] (броник, тактик, плейт...)
- synonyms[] 
- search_queries[] (запити користувачів)
- materials[] (cordura, kydex...)
- standards[] (NIJ, ДСТУ, NATO...)
```

### Команди:
```bash
# Збагатити всі товари без AI індексу
php artisan products:build-ai-index

# Тільки конкретну кількість
php artisan products:build-ai-index --limit=100

# Форсувати перегенерацію
php artisan products:build-ai-index --force
```

### ⚠️ Потрібно:
- [ ] Автоматично запускати після sync нових товарів
- [ ] Scheduled для нових товарів (daily)

---

## 4️⃣ Products → Meilisearch

### Основні файли:
- **Job:** `app/Jobs/IndexProductsToMeiliJob.php`
- **Command:** `app/Console/Commands/ReindexMeiliProducts.php`
- **Setup Command:** `app/Console/Commands/SetupMeiliProducts.php`

### Що індексується:
```
products + product_ai_index → Meilisearch "products" index
- id, article, parent_article, title
- category_path, brand, color
- price, in_stock, quantity
- ai_product_type, ai_category
- description, attributes_text
- search_index (composite field)
- popularity, orders_count, views_count
```

### Налаштування індексу:
```
Searchable: title, article, search_index, ai_product_type, keywords
Filterable: in_stock, brand, ai_category, price, color
Sortable: price, popularity, orders_count, updated_at
Ranking: words, typo, proximity, attribute, sort, exactness
Synonyms: з config/slang_dictionary.php
```

### Команди:
```bash
# Повна переіндексація (async job)
php artisan meili:reindex-products

# Синхронна (для тестів)
php artisan meili:reindex-products-sync

# Тільки налаштування (без даних)
php artisan meili:setup-products
```

### ⚠️ Потрібно:
- [ ] Автоматично запускати після AI enrichment
- [ ] Scheduled для консистентності (daily after sync)

---

## 5️⃣ Synonyms & Slang Dictionary

### Основні файли:
- **Config:** `config/slang_dictionary.php`
- **Service:** `app/Services/Search/SynonymService.php`
- **Commands:** `app/Console/Commands/Generate*Synonyms*.php`

### Статичний словник (30+ категорій):
```php
// config/slang_dictionary.php
'plate_carriers' => [
    'synonyms' => ['плейт керріер', 'плитоноска', 'бронежилет'],
    'slang' => ['броник', 'плейтак', 'плітник'],
],
'tourniquets' => [
    'synonyms' => ['турнікет', 'джгут', 'кровоспинний'],
    'slang' => ['турнік', 'джгутик', 'гумка'],
],
// ... та інші
```

### AI-генеровані синоніми (в БД):
```
- color_synonyms: "чорний" → ["блек", "black", "темний"]
- product_synonyms: "підсумок" → ["pouch", "кишеня", "карман"]
- category_aliases: "Медицина" → ["медичка", "аптечка", "ifak"]
```

### Команди:
```bash
# Генерація синонімів кольорів через AI
php artisan synonyms:colors

# Генерація синонімів типів товарів
php artisan synonyms:products

# Генерація аліасів категорій
php artisan category:aliases
```

---

## 6️⃣ Categories & Scenarios

### Основні файли:
- **Job:** `app/Jobs/RebuildCategoryIndexJob.php`
- **Job:** `app/Jobs/GenerateCategoryScenariosJob.php`
- **Service:** `app/Services/Catalog/CategoryService.php`

### Що синхронізується:
```
products.category_path → categories table
- path, normalized_path, tokens
- product_count, level

products → scenarios + product_tags
- Автогенерація сценаріїв по категоріям
```

### Команди:
```bash
# Перебудова індексу категорій
php artisan categories:rebuild

# Генерація сценаріїв
php artisan scenarios:generate
```

---

## 7️⃣ Product Stats

### Основні файли:
- **Commands:** в `app/Console/Commands/`

### Що оновлюється:
```
order_items → products.orders_count
chat_events (product_click) → products.views_count
products.raw → products.size (переекстракція)
```

### Команди:
```bash
# Оновити кількість замовлень
php artisan products:update-orders-count

# Синхронізувати перегляди
php artisan products:sync-views

# Переекстрактити розміри
php artisan products:reextract-sizes
```

---

## 8️⃣ Embeddings (Semantic Search)

### Основні файли:
- **Job:** `app/Jobs/GenerateProductEmbeddingsJob.php`
- **Command:** `app/Console/Commands/GenerateEmbeddingsCommand.php`

### Що генерується:
```
OpenAI text-embedding-ada-002 → product_ai_index.embedding
- Vector для semantic search
- Batch processing для економії
```

### Команди:
```bash
# Генерація embeddings
php artisan products:generate-embeddings

# З лімітом
php artisan products:generate-embeddings --limit=500
```

---

## 📅 Поточний Schedule

```php
// routes/console.php

// ═══════════════════════════════════════════════════
// DAILY SYNC PIPELINE (послідовно)
// ═══════════════════════════════════════════════════

// 03:00 - Товари з Horoshop
Schedule::command('horoshop:sync-products')
    ->dailyAt('03:00')
    ->withoutOverlapping();

// 03:30 - Замовлення з Horoshop
Schedule::command('orders:sync --days=1')
    ->dailyAt('03:30')
    ->withoutOverlapping();

// 04:00 - AI збагачення нових товарів (50 шт/день)
Schedule::command('products:build-ai-index --limit=50')
    ->dailyAt('04:00')
    ->withoutOverlapping();

// 05:00 - Переіндексація Meilisearch
Schedule::command('meili:reindex-products')
    ->dailyAt('05:00')
    ->withoutOverlapping();

// 06:00 - Оновлення статистики товарів
Schedule::command('products:update-orders-count')
    ->dailyAt('06:00');

// ═══════════════════════════════════════════════════
// WEEKLY TASKS (неділя)
// ═══════════════════════════════════════════════════

// 02:00 - Перебудова категорій
Schedule::command('categories:rebuild')
    ->weeklyOn(0, '02:00')
    ->withoutOverlapping();

// 02:30 - Генерація embeddings (100 шт/тиждень)
Schedule::command('products:generate-embeddings --limit=100')
    ->weeklyOn(0, '02:30')
    ->withoutOverlapping();

// ═══════════════════════════════════════════════════
// TENANT MANAGEMENT
// ═══════════════════════════════════════════════════

// Щогодини - синк лічильників
Schedule::command('tenants:sync-usage')
    ->hourly();

// 1-го числа - скидання місячного ліміту
Schedule::command('tenants:reset-usage --sync')
    ->monthlyOn(1, '00:00');
```

---

## 🎯 Рекомендований Schedule (TODO)

```php
// === HOROSHOP SYNC ===
// Товари - щодня о 03:00
Schedule::command('horoshop:sync-products')
    ->daily()->at('03:00')
    ->withoutOverlapping();

// Замовлення - кожні 15 хвилин
Schedule::command('orders:sync --days=1')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// === AI ENRICHMENT ===
// Нові товари - щодня о 04:00 (після sync)
Schedule::command('products:build-ai-index --limit=100')
    ->daily()->at('04:00')
    ->withoutOverlapping();

// === MEILISEARCH ===
// Переіндексація - щодня о 05:00 (після AI)
Schedule::command('meili:reindex-products')
    ->daily()->at('05:00')
    ->withoutOverlapping();

// === STATS ===
// Оновлення статистики - щодня о 06:00
Schedule::command('products:update-orders-count')
    ->daily()->at('06:00');

// === CATEGORIES ===
// Перебудова категорій - щотижня в неділю
Schedule::command('categories:rebuild')
    ->weekly()->sundays()->at('02:00');
```

---

## 🔗 Flow діаграма

```
┌─────────────────────────────────────────────────────────────────┐
│                         HOROSHOP API                             │
└───────────────────────────┬─────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│  1. horoshop:sync-products (03:00)                               │
│     └─> products table                                           │
└───────────────────────────┬─────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│  2. products:build-ai-index (04:00)                              │
│     └─> OpenAI → product_ai_index                                │
└───────────────────────────┬─────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│  3. meili:reindex-products (05:00)                               │
│     └─> products + AI → Meilisearch                              │
└───────────────────────────┬─────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│  4. products:update-orders-count (06:00)                         │
│     └─> order_items → products.orders_count                      │
└─────────────────────────────────────────────────────────────────┘

          ║                                    ║
          ║  ПАРАЛЕЛЬНО                        ║
          ▼                                    ▼
┌────────────────────────┐      ┌────────────────────────┐
│  orders:sync (*/15min) │      │  categories:rebuild    │
│  └─> orders table      │      │  └─> (weekly)          │
└────────────────────────┘      └────────────────────────┘
```

---

## 📁 Ключові файли

| Категорія | Файли |
|-----------|-------|
| **Schedule** | `routes/console.php` |
| **Sync Logs** | `app/Models/SyncLog.php`, `database/migrations/*_create_sync_logs_table.php` |
| **Super Admin UI** | `app/Livewire/Admin/SyncReports.php`, `resources/views/livewire/admin/sync-reports.blade.php` |
| **Horoshop Client** | `app/Services/Horoshop/HoroshopClient.php` |
| **Product Sync** | `app/Jobs/IncrementalProductSyncJob.php`, `app/Services/Horoshop/ProductService.php` |
| **Orders Sync** | `app/Jobs/FetchHoroshopOrdersJob.php`, `app/Console/Commands/SyncOrdersCommand.php` |
| **AI Enrichment** | `app/Jobs/EnrichProductWithAiJob.php`, `app/Services/Ai/ProductEnrichmentService.php` |
| **Meilisearch** | `app/Jobs/IndexProductsToMeiliJob.php`, `config/meilisearch.php` |
| **Synonyms** | `config/slang_dictionary.php`, `app/Services/Search/SynonymService.php` |

---

## ⚡ Quick Reference

```bash
# === ПОВНИЙ РУЧНИЙ SYNC ===

# 1. Товари з Horoshop
php artisan horoshop:sync

# 2. Замовлення з Horoshop
php artisan orders:sync --days=7

# 3. AI збагачення
php artisan products:build-ai-index

# 4. Meilisearch індекс
php artisan meili:reindex-products

# 5. Оновити статистику
php artisan products:update-orders-count

# 6. Категорії
php artisan categories:rebuild

# === ПЕРЕВІРКА ===

# Статус Meili
curl "http://localhost:7700/indexes/products/stats"

# Пошук тест
curl "http://localhost:7700/indexes/products/search" \
  -H "Authorization: Bearer $MEILI_MASTER_KEY" \
  -d '{"q": "плейт керріер"}'

# Кількість товарів
php artisan tinker --execute="echo App\Models\Product::count();"

# Кількість з AI індексом  
php artisan tinker --execute="echo App\Models\Product::whereHas('aiIndex')->count();"

# === ЛОГИ ===

# Перегляд логів синхронізації
tail -f storage/logs/sync-horoshop.log
tail -f storage/logs/sync-ai-enrichment.log
tail -f storage/logs/sync-meilisearch.log
```

---

## 🗄️ Таблиця sync_logs

Для відстеження історії синхронізацій створена таблиця `sync_logs`:

```sql
CREATE TABLE sync_logs (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    sync_type VARCHAR(50),        -- horoshop_products, orders, ai_enrichment, meilisearch, categories, embeddings, stats
    status VARCHAR(20),           -- running, completed, failed
    started_at TIMESTAMP,
    finished_at TIMESTAMP,
    duration_seconds INT,
    total_processed INT,
    created INT,
    updated INT,
    skipped INT,
    failed INT,
    metrics JSON,                 -- { "in_stock": 1234, "meili_docs": 1500 }
    error_message TEXT,
    notes TEXT,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

### Використання в Jobs:

```php
use App\Models\SyncLog;

// На початку job
$log = SyncLog::start(SyncLog::TYPE_HOROSHOP_PRODUCTS, 'Daily sync');

try {
    // ... sync logic ...
    
    $log->complete([
        'total_processed' => 1500,
        'created' => 10,
        'updated' => 50,
        'skipped' => 1440,
    ], [
        'in_stock' => 1234,
        'out_of_stock' => 266,
    ]);
} catch (\Exception $e) {
    $log->fail($e->getMessage());
    throw $e;
}
```
