# Product Enrichment (AI-збагачення товарів)

Документація описує систему AI-збагачення товарів для покращення пошуку.

## 📊 Архітектура

```
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│   Horoshop API  │───>│  products       │───>│ product_ai_index│───>│   Meilisearch   │
│   (raw JSON)    │    │  (MySQL)        │    │  (MySQL)        │    │   (index)       │
└─────────────────┘    └─────────────────┘    └─────────────────┘    └─────────────────┘
                            │                        │
                            │                        │
                       Sync Products            AI Enrichment
                       (artisan)                (OpenAI GPT)
```

### Data Flow

1. **Horoshop Sync** → `products` таблиця зберігає:
   - `raw` (JSON) — повний payload з API
   - `title`, `category_path`, `brand`, `color`, `price`, `in_stock`
   - `search_index` — об'єднаний текст для пошуку

2. **AI Enrichment** → `product_ai_index` таблиця зберігає:
   - `product_type` — тип товару (plate_carrier, helmet, boots, etc)
   - `ai_category` — категорія (armor, apparel, footwear, etc)
   - `keywords` — ключові слова українською/англійською
   - `slang` — жаргонні назви ("плитка", "бронік", "берци")
   - `materials`, `standards`, `usage`
   - `raw_ai_json` — повна відповідь AI для аудиту

3. **Meilisearch Index** — об'єднує дані з обох таблиць:
   ```json
   {
     "id": 123,
     "title": "Плитоноска Creed AVS",
     "category_path": "Бронезахист/Плитоноски",
     "ai_product_type": "plate_carrier",
     "ai_category": "armor",
     "has_ai_type": true
   }
   ```

## 🔧 Команди

### Синхронізація товарів з Horoshop
```bash
# Повна синхронізація
php artisan horoshop:sync-products

# З логами
php artisan horoshop:sync-products -vvv
```

### AI Enrichment
```bash
# Збагатити всі товари без AI-індексу
php artisan products:build-ai-index --only-missing

# Збагатити незавершені (без product_type)
php artisan products:build-ai-index --incomplete

# Тільки fallback (без API викликів)
php artisan products:build-ai-index --no-ai

# З таймаутом для cloud (10 хв)
php artisan products:build-ai-index --timeout=600 --resume

# Статистика
php artisan products:build-ai-index --stats
```

### Meilisearch реіндексація
```bash
# Синхронна реіндексація
php artisan meili:reindex-sync

# Через Job (асинхронно)
php artisan meili:reindex-job
```

## 📦 Моделі та таблиці

### `products` таблиця
| Поле | Тип | Опис |
|------|-----|------|
| `id` | int | Primary key |
| `article` | string | Артикул товару |
| `parent_article` | string | Артикул батьківського товару |
| `title` | string | Назва товару |
| `category_path` | string | Шлях категорії (Бронезахист/Плитоноски) |
| `brand` | string | Бренд |
| `color` | string | Колір |
| `size` | string | Розмір |
| `price` | decimal | Ціна |
| `in_stock` | boolean | Наявність |
| `quantity` | int | Кількість на складі |
| `raw` | JSON | Повний payload з Horoshop API |
| `search_index` | text | Об'єднаний текст для пошуку |
| `orders_count` | int | Кількість замовлень (з Order sync) |
| `popularity` | int | Популярність з Horoshop |

### `product_ai_index` таблиця
| Поле | Тип | Опис |
|------|-----|------|
| `product_id` | int | FK до products |
| `product_type` | string | Тип (plate_carrier, helmet, boots) |
| `ai_category` | string | Категорія (armor, apparel, footwear) |
| `keywords` | JSON | Масив ключових слів |
| `slang` | JSON | Масив жаргону ["плитка", "бронік"] |
| `materials` | JSON | Матеріали ["cordura", "nylon"] |
| `standards` | JSON | Стандарти ["NIJ III", "ДСТУ"] |
| `usage` | JSON | Призначення ["assault", "training"] |
| `raw_ai_json` | JSON | Повна відповідь AI |

## 🤖 AI Enrichment Process

### Prompt для GPT
```text
Проаналізуй цей товар військового спорядження та згенеруй JSON:

ТОВАР:
Назва: {title}
Категорія: {category}
Опис: {description}
Характеристики: {characteristics}

Згенеруй JSON з полями:
1. product_type: тип англійською (plate_carrier, helmet, boots)
2. ai_category: категорія (armor, apparel, footwear)
3. keywords: масив ключових слів УКР/ENG
4. slang: жаргон як шукають люди ["плитка", "бронік"]
5. materials: матеріали ["cordura", "nylon"]
6. standards: стандарти ["NIJ III", "ДСТУ"]
7. usage: призначення ["assault", "training"]
```

### Fallback (без AI)
Якщо OpenAI недоступний, використовується rule-based mapping:
```php
// ProductIndexBuilder::mapTypeFromCategory()
if (str_contains($category, 'шолом')) return 'helmet';
if (str_contains($category, 'плитоноск')) return 'plate_carrier';
if (str_contains($category, 'футболк')) return 'tshirt';
```

## ⚠️ Слабкі місця та проблеми

### 1. **Неповне покриття AI-індексу**

**Проблема:** Не всі товари мають AI enrichment
```
Всього товарів: ~2336
З product_type: ~???
```

**Наслідки:**
- Пошук по жаргону ("плитка", "бронік") не працює для товарів без slang
- Фільтрація по типу (ai_product_type) неповна
- Ранжування гірше для товарів без AI-даних

**Рішення:**
```bash
# Запустити enrichment для всіх товарів
php artisan products:build-ai-index --only-missing --timeout=3600
```

### 2. **Застарілий AI-індекс**

**Проблема:** ~~Після sync нових товарів вони не мають AI-індексу~~ **ВИРІШЕНО!**

**Реалізоване рішення (v2025.01):**
Автоматично після sync товарів запускаються:
1. `IndexProductsToMeiliJob::dispatch($tenantId)` — реіндексація в Meilisearch
2. `AnalyzeProductsWithAiJob::dispatch(tenantId: $tenantId)` — AI enrichment для нових товарів

```php
// SyncHoroshopProductsJob автоматично тригерить:
if ($changedCount > 0) {
    IndexProductsToMeiliJob::dispatch($tenantId)->delay(5s);
}
if ($createdCount > 0) {
    AnalyzeProductsWithAiJob::dispatch(tenantId: $tenantId)->delay(1min);
}
```

Jobs тепер підтримують `tenant_id` параметр для tenant-specific операцій.

### 3. **Якість slang/keywords**

**Проблема:** GPT не завжди генерує правильний жаргон

**Приклади помилок:**
- "берці" → ["берці"] (правильно: ["берци", "черевики", "боти"])
- "плитоноска" → ["плитоноска"] (правильно: ["плитка", "бронік", "pc"])
- Немає сленгу взагалі

**Наслідки:**
- Пошук "покажи плитки" не знаходить плитоноски
- Пошук "хороші боти" не знаходить берці

**Рішення:**
1. Поліпшити prompt з конкретними прикладами сленгу
2. Додати manual override таблицю для критичних категорій
3. Post-processing для доповнення slang з словника

### 4. **Вартість AI-викликів**

**Проблема:** ~2300 товарів × $0.01 = ~$23 на повний enrichment

**Наслідки:**
- Дорого перезапускати для всіх товарів
- Не можна часто оновлювати

**Рішення:**
1. Використовувати `gpt-4o-mini` замість `gpt-4o` (~10x дешевше)
2. Batch processing — групувати товари
3. Incremental updates — тільки нові/змінені товари

### 5. **Неконсистентність product_type**

**Проблема:** GPT генерує різні назви для одного типу
```
plate_carrier vs platecarrier vs plate-carrier
helmet vs combat_helmet vs ballistic_helmet
```

**Наслідки:**
- Фільтрація по типу неповна
- "helmet" не знайде "combat_helmet"

**Рішення:**
1. Strict enum в prompt:
```text
product_type MUST be one of: plate_carrier, helmet, boots, gloves, pouch, backpack, uniform, armor_plate
```
2. Post-processing normalization

### 6. **Відсутність embedding**

**Проблема:** Поле `embedding` завжди NULL

**Наслідки:**
- Немає semantic search
- Тільки keyword matching

**Рішення:**
Додати embedding generation:
```php
$embedding = $ai->embeddings($title . ' ' . $description);
```

### 7. **Дублікати варіантів**

**Проблема:** Товар з 10 кольорами = 10 записів з однаковим AI-індексом

**Наслідки:**
- Зайві витрати на API
- Дублікати в результатах

**Рішення:**
1. Enrichment тільки для parent_article
2. Копіювання AI-індексу на варіанти

### 8. **Rate Limiting / Timeouts**

**Проблема:** OpenAI має ліміти на requests/min

**Наслідки:**
- Job падає при масовому enrichment
- Незавершений індекс

**Рішення:**
1. Exponential backoff вже є в ProductIndexBuilder
2. `--resume` для продовження з останньої позиції
3. Менші batch size для cloud functions

## 📈 Метрики та моніторинг

### Перевірка покриття
```bash
# Статистика AI-індексу
php artisan products:build-ai-index --stats

# Або через tinker
php artisan tinker
>>> ProductAiIndex::whereNotNull('product_type')->count()
>>> ProductAiIndex::whereNotNull('slang')->count()
```

### Діагностичний endpoint
```bash
curl "https://aimbot.laravel.cloud/api/diagnostic/db-stats?key=..." | jq '.ai_index_stats'
```

## 🛠️ TODO / Roadmap

1. [x] **Автоматичний enrichment** після sync нових товарів — додано в Kernel.php (щоденно о 04:00)
2. [x] **Slang dictionary** — ручний словник сленгу `config/slang_dictionary.php` + SlangDictionaryService
3. [x] **Embeddings** — EmbeddingService + SemanticSearchService (semantic search fallback)
4. [x] **Quality scoring** — EnrichmentQualityService з score 0-100 та grade A-F
5. [x] **A/B testing** — ABTestingService для порівняння search quality з/без AI
6. [x] **Parent-based enrichment** — ParentBasedEnrichmentJob (один запит на parent_article)
7. [x] **Incremental sync** — IncrementalProductSyncJob (кожні 4 години, тільки змінені товари)
8. [x] **Monitoring dashboard** — Quality Score в Analytics + diagnostic API endpoints

## 📚 Пов'язані файли

- [app/Jobs/AnalyzeProductsWithAiJob.php](../app/Jobs/AnalyzeProductsWithAiJob.php) — Job для AI-аналізу
- [app/Services/Ai/ProductIndexBuilder.php](../app/Services/Ai/ProductIndexBuilder.php) — Builder з fallback
- [app/Services/Search/SlangDictionaryService.php](../app/Services/Search/SlangDictionaryService.php) — Сервіс словника сленгу
- [config/slang_dictionary.php](../config/slang_dictionary.php) — Ручний словник сленгу
- [app/Models/ProductAiIndex.php](../app/Models/ProductAiIndex.php) — Модель AI-індексу
- [app/Console/Commands/BuildProductAiIndex.php](../app/Console/Commands/BuildProductAiIndex.php) — Artisan command
- [app/Console/Commands/MeiliReindexProductsSync.php](../app/Console/Commands/MeiliReindexProductsSync.php) — Meilisearch sync

## 📖 Slang Dictionary

### Структура словника

Словник знаходиться в `config/slang_dictionary.php` і містить:

```php
'plate_carrier' => [
    'slang' => ['плитка', 'бронік', 'pc'],        // Жаргон
    'synonyms' => ['плитоноска', 'бронежилет'],   // Офіційні назви
    'typos' => ['плитноска', 'плейткеріер'],      // Помилки
    'en' => ['plate carrier', 'body armor'],      // Англійська
],
```

### Використання

```php
use App\Services\Search\SlangDictionaryService;

$dict = app(SlangDictionaryService::class);

// Знайти тип по терміну
$type = $dict->findTypeByTerm('плитка'); // 'plate_carrier'

// Розширити пошуковий запит
$expanded = $dict->expandQuery('хочу плитку'); 
// '(плитка OR плитоноска OR бронік) хочу'

// Отримати весь сленг для типу
$slang = $dict->getSlangForType('helmet');
// ['шлем', 'каска', 'череп', 'helmet', ...]
```

### Augmentation в AI Index

При AI enrichment автоматично доповнюється slang з словника:

1. AI генерує `slang` для товару
2. `ProductIndexBuilder` визначає `product_type`
3. Словник доповнює slang з `config/slang_dictionary.php`
4. Результат зберігається в `product_ai_index.slang`

### Додавання нових термінів

1. Відкрийте `config/slang_dictionary.php`
2. Додайте новий тип або розширте існуючий
3. Очистіть кеш: `php artisan cache:clear`
4. Перезапустіть enrichment для оновлення індексу

## 📊 Quality Scoring

### Оцінка якості AI-індексу

```bash
# Отримати quality score та статистику
curl "https://aimbot.laravel.cloud/api/diagnostic/ai-index-stats?key=..."

# Отримати список проблем
curl "https://aimbot.laravel.cloud/api/diagnostic/ai-index-problems?key=..."
```

### Метрики якості

| Метрика | Вага | Опис |
|---------|------|------|
| coverage_percent | 30% | % товарів з AI-індексом |
| type_coverage_percent | 25% | % з product_type |
| slang_coverage_percent | 25% | % зі slang |
| keywords_coverage_percent | 20% | % з keywords |

### Grades

| Score | Grade | Опис |
|-------|-------|------|
| 90-100 | A | Відмінно |
| 80-89 | B | Добре |
| 70-79 | C | Задовільно |
| 60-69 | D | Потребує уваги |
| <60 | F | Критично |

### EnrichmentQualityService

```php
use App\Services\Ai\EnrichmentQualityService;

$service = app(EnrichmentQualityService::class);

// Загальний score
$quality = $service->getOverallScore();
// ['score' => 85.2, 'grade' => 'B', 'stats' => [...]]

// Знайти проблеми
$problems = $service->findProblems(50);
// ['no_ai_index' => [...], 'no_slang' => [...], ...]

// Рекомендації
$recs = $service->getRecommendations();
// [['priority' => 'high', 'action' => 'Run AI enrichment', ...]]
```

## 🧠 Embeddings (Semantic Search)

### Концепція

Embeddings — векторні представлення тексту, що дозволяють знаходити товари за змістом, а не тільки за ключовими словами.

**Приклади:**
| Запит | Keyword search | Semantic search |
|-------|---------------|-----------------|
| "захист для голови" | ❌ | ✅ шоломи, каски |
| "щось теплое на зиму" | ❌ | ✅ куртки, флісові кофти |
| "для ніг на полігон" | ❌ | ✅ черевики, наколінники |

### Використання

```bash
# Статистика embeddings
php artisan products:generate-embeddings --stats

# Генерація embeddings (async через queue)
php artisan products:generate-embeddings

# Генерація синхронно
php artisan products:generate-embeddings --sync --batch=50

# Обмежити кількість
php artisan products:generate-embeddings --limit=100 --sync
```

### EmbeddingService

```php
use App\Services\Ai\EmbeddingService;

$service = app(EmbeddingService::class);

// Генерація одного embedding
$embedding = $service->embed("плитоноска тактична");
// [0.023, -0.156, 0.892, ...] // 1536 чисел

// Batch генерація
$embeddings = $service->embedBatch(["плитоноска", "шолом", "черевики"]);

// Cosine similarity
$similarity = $service->cosineSimilarity($embedding1, $embedding2);
// 0.0 - 1.0
```

### SemanticSearchService

```php
use App\Services\Search\SemanticSearchService;

$search = app(SemanticSearchService::class);

// Семантичний пошук
$results = $search->search("захист для голови", limit: 10, filters: ['in_stock' => true]);

// Знайти схожі товари
$similar = $search->findSimilar($product, limit: 5);

// Гібридний пошук (keyword + semantic)
$hybrid = $search->hybridSearch($query, $keywordResults, limit: 20);
```

### Інтеграція з Agent

`MeiliProductSearchTool` автоматично використовує semantic search як fallback коли keyword search повертає < 3 результатів:

```
User: "щось для захисту очей"
↓
MeiliSearch: 0 results (no "захист очей" in titles)
↓
SemanticSearchService: finds "Окуляри балістичні" (similar meaning)
↓
User: отримує релевантні товари
```

### Вартість

- **Model:** text-embedding-3-small
- **Cost:** ~$0.00002 / 1000 токенів
- **~2300 товарів:** ~$0.10-0.50 разова генерація
- Embeddings кешуються на 30 днів

### Налаштування

```env
# В .env (опціонально)
OPENAI_EMBEDDING_MODEL=text-embedding-3-small
OPENAI_EMBEDDING_DIMENSIONS=1536
```

## 🔄 Parent-based Enrichment

### Концепція

Замість збагачення кожного варіанту товару (10 кольорів = 10 API запитів):
1. Групуємо товари по `parent_article`
2. Збагачуємо тільки "головний" товар
3. Копіюємо AI-індекс на всі варіанти

**Економія:** ~80% API викликів

### Використання

```bash
# Через Job (рекомендовано)
php artisan tinker
>>> App\Jobs\ParentBasedEnrichmentJob::dispatch(20, 0, true);

# Або artisan command
php artisan products:build-ai-index --parent-based
```

### ParentBasedEnrichmentJob

```php
use App\Jobs\ParentBasedEnrichmentJob;

// Збагатити всі parent groups без AI-індексу
ParentBasedEnrichmentJob::dispatch(
    batchSize: 20,    // Кількість parent_article за раз
    offset: 0,        // Offset для resume
    onlyMissing: true // Тільки ті що немають індексу
);
```

## 🔄 Incremental Sync

### Концепція

Замість повної синхронізації всіх ~2300+ товарів щодня:
1. Порівнюємо хеш товару (price, quantity, title) з кешованим
2. Оновлюємо тільки змінені/нові товари
3. Позначаємо видалені як `in_stock: false`
4. Автоматично тригеримо AI enrichment та Meili reindex

**Переваги:**
- Швидше (~секунди замість хвилин для малих змін)
- Менше навантаження на БД
- Частіші оновлення (кожні 4 години)

### Використання

```bash
# Через artisan (синхронно, з виводом статистики)
php artisan products:incremental-sync --sync

# Через Job (асинхронно)
php artisan products:incremental-sync

# Без AI enrichment
php artisan products:incremental-sync --no-enrichment

# Без Meili reindex
php artisan products:incremental-sync --no-meili
```

### IncrementalProductSyncJob

```php
use App\Jobs\IncrementalProductSyncJob;

// Повний sync з enrichment та reindex
IncrementalProductSyncJob::dispatch(true, true);

// Тільки sync без додаткових jobs
IncrementalProductSyncJob::dispatch(false, false);
```

### Scheduler

Запускається автоматично кожні 4 години в production:
- 00:00, 04:00, 08:00, 12:00, 16:00, 20:00

Повна синхронізація (`SyncHoroshopProductsJob`) запускається о 03:00 щодня як fallback.

### Статистика останнього sync

```php
// Отримати статистику
$stats = Cache::get('incremental_sync_stats');
// [
//   'stats' => ['new' => 5, 'updated' => 12, 'unchanged' => 2280, 'deleted' => 0],
//   'elapsed_seconds' => 45.2,
//   'completed_at' => '2025-01-14T12:00:00+00:00'
// ]
```
