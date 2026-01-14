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

**Проблема:** Після sync нових товарів вони не мають AI-індексу

**Наслідки:**
- Нові товари гірше знаходяться
- Inconsistent search results

**Рішення:**
Автоматично запускати enrichment після sync:
```php
// В SyncHoroshopProducts після успішного sync
AnalyzeProductsWithAiJob::dispatch()->delay(now()->addMinutes(5));
```

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

1. [ ] **Автоматичний enrichment** після sync нових товарів
2. [ ] **Slang dictionary** — ручний словник сленгу для категорій
3. [ ] **Embeddings** — semantic search
4. [ ] **Quality scoring** — оцінка якості AI-індексу
5. [ ] **A/B testing** — порівняння search quality з/без AI
6. [ ] **Parent-based enrichment** — один запит на parent_article
7. [ ] **Incremental sync** — тільки змінені товари
8. [ ] **Monitoring dashboard** — coverage, quality, costs

## 📚 Пов'язані файли

- [app/Jobs/AnalyzeProductsWithAiJob.php](../app/Jobs/AnalyzeProductsWithAiJob.php) — Job для AI-аналізу
- [app/Services/Ai/ProductIndexBuilder.php](../app/Services/Ai/ProductIndexBuilder.php) — Builder з fallback
- [app/Models/ProductAiIndex.php](../app/Models/ProductAiIndex.php) — Модель AI-індексу
- [app/Console/Commands/BuildProductAiIndex.php](../app/Console/Commands/BuildProductAiIndex.php) — Artisan command
- [app/Console/Commands/MeiliReindexProductsSync.php](../app/Console/Commands/MeiliReindexProductsSync.php) — Meilisearch sync
