# 🔍 Система Пошуку — Детальна Документація

> **Остання оновлення**: 22.12.2025  
> **Статус**: ✅ Працює (з невеликими багами)  
> **Відповідальний**: Search & AI Pipeline

---

## 📋 Зміст
1. [Огляд](#огляд)
2. [Архітектура Pipeline](#архітектура-pipeline)
3. [Компоненти](#компоненти)
4. [Flow Пошуку](#flow-пошуку)
5. [Проблеми та Рішення](#проблеми-та-рішення)

---

## Огляд

Система пошуку — це багатоетапний AI-керований pipeline для пошуку товарів за користувацькими запитами.

### Ключові Особливості
- 🎯 **40→10→3-10 Funnel**: 40 кандидатів → дедуплікація → AI вибирає 3-10
- 🤖 **AI на кожному етапі**: Intent classification, query normalization, reranking
- 🏷️ **Brand Boosting**: "hoffmann" → "HOFFMANN HOFFMANN HOFFMANN"
- 🛡️ **Accessory Filtering**: Агресивна фільтрація ременів, кап, чохлів
- 📊 **Context-Aware**: Контекст визначає чи показувати аксесуари

---

## Архітектура Pipeline

```
User Message
    ↓
┌──────────────────────────────────────┐
│  AgentOrchestrator                   │
│  • Intent Classification (AiRouter)  │
│  • Query Normalization (AiRouter)    │
└──────────────────────────────────────┘
    ↓
┌──────────────────────────────────────┐
│  Step 1: MeiliProductSearchTool      │
│  • Brand Detection (3x boost)        │
│  • Meilisearch query (40 results)    │
│  • Strict Accessory Detection        │
└──────────────────────────────────────┘
    ↓ 40 candidates
┌──────────────────────────────────────┐
│  Step 2: DeduperTool                 │
│  • Group by parent_article           │
│  • Keep highest popularity           │
└──────────────────────────────────────┘
    ↓ ~30-35 unique products
┌──────────────────────────────────────┐
│  Step 3: AccessoryFilterTool         │
│  • Категоризація (main/accessories)  │
│  • Remove accessories if ≥3 main     │
└──────────────────────────────────────┘
    ↓ ~20-25 products
┌──────────────────────────────────────┐
│  Step 4: AiRerankTool                │
│  • AI relevance scoring (GPT-4.1)    │
│  • Dynamic limit (3-10 products)     │
│  • Quality over quantity             │
└──────────────────────────────────────┘
    ↓ 3-10 most relevant
┌──────────────────────────────────────┐
│  Step 5: ProductDetailsTool          │
│  • Fetch full product cards          │
│  • Format for frontend               │
└──────────────────────────────────────┘
    ↓
Final Response to User
```

---

## Компоненти

### 1. AgentOrchestrator
**Файл**: [app/Services/Agent/AgentOrchestrator.php](../../app/Services/Agent/AgentOrchestrator.php)

**Роль**: Головний контролер всього pipeline.

**Що робить**:
- Викликає `AiRouter::classify()` для визначення інтенції (product_search, order_status, faq, smalltalk)
- Викликає `AiRouter::normalizeSearchQuery()` для нормалізації запиту
- Виконує pipeline через tools
- Генерує follow-up питання (якщо запит ambiguous)

**Приклад**:
```php
$result = $orchestrator->handle("плитоноска зелена до 5000", []);
// Повертає:
[
    'message' => 'Ось плитоноски зеленого кольору до 5000 грн:',
    'products' => [...],
    'meta' => [
        'intent' => 'product_search',
        'refined_query' => 'плитоноска',
        'filters' => ['color' => 'зелена', 'budget_max' => 5000],
        'chosen_ids' => [123, 456, 789],
        'search_debug' => [...]
    ]
]
```

---

### 2. MeiliProductSearchTool
**Файл**: [app/Services/Agent/Tools/MeiliProductSearchTool.php](../../app/Services/Agent/Tools/MeiliProductSearchTool.php)

**Роль**: Первинний пошук + brand detection + accessory filtering.

**Етапи**:
1. **Brand Detection**: `BrandDetectionService->detectBrand(query)`
   - Якщо знайдено бренд → `enhanced_query = "БРЕНД БРЕНД БРЕНД $original_query"`
   - Meilisearch ранкує документи з частішим повтором вище

2. **Meilisearch Query**: 
   ```php
   $index->search($enhancedQuery, [
       'filter' => 'in_stock = true',
       'limit' => 40,
       'attributesToRetrieve' => [...],
   ])
   ```

3. **Strict Accessory Detection**:
   - Сканує кожен продукт на ключові слова: `камбербанд`, `кап`, `чохол`, `сумка`, `кріплення`, `адаптер`, `подушки`, `ремінь`, `модуль`
   - Якщо знайдено → категоризує як `accessory`
   - **Context Awareness**: 
     - Якщо запит "панель для плитоноски" → НЕ фільтрувати панелі
     - Якщо запит "ремінь для рюкзака" → НЕ фільтрувати ремені
     - Використовує regex: `/^панел|панель для|^ремінь|ремінь для/ui`

4. **Aggressive Filtering**:
   - Якщо ≥3 main products → видалити всі accessories з результатів
   - Логування: `MeiliProductSearchTool: removing accessories, enough main products`

**Відомі Проблеми**:
- ✅ FIXED: Бренд-детекція працює, але AI reranker не поважав бренд
- ⚠️ TODO: Hardcoded accessory keywords — треба винести в БД

**Приклад логів**:
```json
{
  "message": "MeiliProductSearchTool: searching",
  "context": {
    "original_query": "hoffmann",
    "is_brand_search": true,
    "detected_brand": "HOFFMANN",
    "enhanced_query": "HOFFMANN HOFFMANN HOFFMANN",
    "limit": 10
  }
}
```

---

### 3. DeduperTool
**Файл**: [app/Services/Agent/Tools/DeduperTool.php](../../app/Services/Agent/Tools/DeduperTool.php)

**Роль**: Видаляє дублікати варіантів товару (розміри, кольори).

**Логіка**:
- Group products by `parent_article`
- У кожній групі залишити **1 варіант з найвищим `popularity`**
- Якщо `parent_article` NULL → залишити як є

**Приклад**:
```
Input: 40 products (10 розмірів шоломів MICH 2000)
Output: 30 products (1 шолом MICH 2000)
```

**Performance**: O(n) — одне проходження масивом.

---

### 4. AccessoryFilterTool
**Файл**: [app/Services/Agent/Tools/AccessoryFilterTool.php](../../app/Services/Agent/Tools/AccessoryFilterTool.php)

**Роль**: Downrank/видаляє аксесуари якщо є достатньо основних товарів.

**Логіка**:
1. Категоризує products на `main` vs `accessories` (аналогічні ключові слова до MeiliProductSearchTool)
2. Якщо `main >= 3` → видалити всі `accessories`
3. Якщо `main < 3` → залишити accessories (можуть бути релевантні)

**Приклад**:
```
Input: 25 products (5 плитоноски, 20 ременів/панелей)
↓
Output: 5 products (тільки плитоноски)
```

**Відомі Проблеми**:
- ⚠️ Дублює логіку з MeiliProductSearchTool — треба об'єднати
- ⚠️ Може видалити релевантні панелі якщо є плитоноски

---

### 5. AiRerankTool
**Файл**: [app/Services/Agent/Tools/AiRerankTool.php](../../app/Services/Agent/Tools/AiRerankTool.php)

**Роль**: AI-керована переранкація + **динамічний ліміт 3-10**.

**Промпт Особливості**:
- ✅ "Якщо є 3-4 ідеальних + 6 посередніх → вибери тільки 3-4"
- ✅ "Якість > кількість"
- ✅ "Основні товари перші, аксесуари останні"
- ✅ Приклади: "шеврон група крові" → 4 шеврони (НЕ додавай MED, СБУ)

**Проблема** (станом на 22.12.2025):
- 🔴 **AI не поважав бренд**: Навіть якщо MeiliProductSearchTool повертав тільки HOFFMANN, AI міг переставити KOMBAT UK на перше місце через вищу `popularity`
- ✅ **Фікс**: Оновлено промпт з інструкцією "ПОВАЖАТИ БРЕНД У НАЗВІ, навіть якщо popularity нижча"
- ⚠️ **Статус**: Промпт покращено, але **НЕ ЗАКОМІЧЕНО** — треба закомітити зміни

**API Call**:
```php
$prompt = $this->buildRerankPrompt($candidates, $query, $filters);
$response = $this->aiRouter->callOpenAI($prompt, 0.3); // temperature=0.3 для стабільності
$result = json_decode($response, true);
// Expected: { "chosen_ids": [123, 456], "reasoning": {...} }
```

**Response**:
- Повертає **тільки обрані AI products** (без fallback padding)
- Якщо AI обрав 4 товари → повертається 4 (НЕ 10)

---

### 6. ProductDetailsTool
**Файл**: [app/Services/Agent/Tools/ProductDetailsTool.php](../../app/Services/Agent/Tools/ProductDetailsTool.php)

**Роль**: Fetching повних карток товару для відображення.

**Що робить**:
- Приймає масив ID
- Query Eloquent з relations (якщо є)
- Форматує у стандартний формат:
  ```php
  [
      'id' => 123,
      'article' => 'ABC-123',
      'title' => 'Плитоноска АТАКА',
      'price' => 12000,
      'price_old' => 15000,
      'images' => [...],
      'category_path' => 'Тактичне спорядження/Плитоноски',
      'brand' => 'АТАКА',
      'in_stock' => true,
  ]
  ```

**Performance**: Batch query за ID — O(1) DB запит.

---

## Flow Пошуку

### Приклад 1: "hoffmann"
```
1. AgentOrchestrator::createPlan()
   ↓ AiRouter::classify() → PRODUCT_SEARCH
   ↓ AiRouter::normalizeSearchQuery() → "hoffmann"

2. MeiliProductSearchTool::search("hoffmann")
   ↓ BrandDetectionService → detected: HOFFMANN
   ↓ Enhanced query: "HOFFMANN HOFFMANN HOFFMANN"
   ↓ Meilisearch → 10 results (всі HOFFMANN патчі)
   ↓ Accessory detection → 1 accessory, 9 main
   ↓ Filter accessories → 9 products

3. DeduperTool::dedupe(9)
   ↓ No duplicates → 9 products

4. AccessoryFilterTool::downrankAccessories(9)
   ↓ main=9, accessories=0 → no changes

5. AiRerankTool::rerank(9)
   ↓ AI prompt: "обери найрелевантніші товари"
   ↓ AI response: chosen_ids=[975, 976, 977, 2232, 2269, 2270, 2271, 2273, 2274]
   ↓ 9 products (AI обрав всі, бо всі релевантні)

6. ProductDetailsTool::getCards([975, 976, ...])
   ↓ Fetch full cards → 9 products

RESULT: 9 патчів HOFFMANN
```

### Приклад 2: "плитоноска" (показує ремені, треба відфільтрувати)
```
1. AgentOrchestrator → intent: product_search, query: "плитоноска"

2. MeiliProductSearchTool::search("плитоноска")
   ↓ No brand detected
   ↓ Meilisearch → 40 results (плитоноски + ремені + панелі)
   ↓ Accessory detection → 30 accessories, 10 main
   ↓ Filter accessories → 10 main products (тільки плитоноски)

3. DeduperTool → 8 unique products

4. AccessoryFilterTool → already filtered

5. AiRerankTool → AI обирає 5-7 найкращих

6. ProductDetailsTool → 5-7 плитоносок

RESULT: Тільки плитоноски, без ременів
```

---

## Проблеми та Рішення

### ✅ FIXED: Brand search shows wrong brands
**Проблема**: "hoffmann" показував KOMBAT UK замість HOFFMANN  
**Root Cause**: AI reranker не поважав бренд, сортував по popularity  
**Рішення**: 
1. ✅ Brand detection працював правильно (3x boost)
2. ✅ MeiliProductSearchTool повертав правильні результати
3. ⚠️ **TODO**: Закомітити покращений промпт AiRerankTool з інструкцією про бренд

---

### ✅ FIXED: "плитоноска" shows accessories
**Проблема**: Пошук "плитоноска" показував ремені, камбербанди, панелі  
**Root Cause**: Meilisearch ранкував accessories вище через popularity  
**Рішення**: Агресивна фільтрація в MeiliProductSearchTool + AccessoryFilterTool

---

### ⚠️ TODO: "панель для плитоноски" shows nothing
**Проблема**: Контекстний пошук панелей не працює якщо фільтрується  
**Рішення**: Context-aware regex patterns (`^панел|панель для`) — **реалізовано, треба протестувати**

---

### 🔴 CRITICAL: Hardcoded accessory keywords
**Проблема**: Ключові слова в коді (камбербанд, кап, чохол, ...)  
**Рішення**: Створити таблицю `accessory_keywords` в БД  
**Priority**: Medium (працює, але не maintainable)

---

### 🔴 CRITICAL: Duplicate logic (MeiliProductSearchTool + AccessoryFilterTool)
**Проблема**: Два інструменти роблять одне й те саме (accessory detection)  
**Рішення**: Об'єднати логіку, залишити тільки в MeiliProductSearchTool  
**Priority**: Low (працює, але code smell)

---

## Метрики Performance

| Stage | Input | Output | Time |
|-------|-------|--------|------|
| MeiliProductSearchTool | query | 40 products | ~50-70ms |
| DeduperTool | 40 | ~30-35 | ~5ms |
| AccessoryFilterTool | 30 | ~20-25 | ~2ms |
| AiRerankTool | 20 | 3-10 | ~500-800ms ⚠️ |
| ProductDetailsTool | 3-10 | 3-10 | ~15ms |
| **TOTAL** | | | **~600-900ms** |

⚠️ **Bottleneck**: AiRerankTool (OpenAI API call)

---

## Code References

### Файли
- [AgentOrchestrator.php](../../app/Services/Agent/AgentOrchestrator.php) — головний оркестратор
- [MeiliProductSearchTool.php](../../app/Services/Agent/Tools/MeiliProductSearchTool.php) — Meilisearch + brand detection
- [AiRerankTool.php](../../app/Services/Agent/Tools/AiRerankTool.php) — AI переранкація
- [DeduperTool.php](../../app/Services/Agent/Tools/DeduperTool.php) — дедуплікація варіантів
- [AccessoryFilterTool.php](../../app/Services/Agent/Tools/AccessoryFilterTool.php) — фільтр аксесуарів
- [ProductDetailsTool.php](../../app/Services/Agent/Tools/ProductDetailsTool.php) — fetch details
- [BrandDetectionService.php](../../app/Services/Search/BrandDetectionService.php) — бренд-детекція
- [MeiliClient.php](../../app/Services/Search/MeiliClient.php) — Meilisearch wrapper

### Tests
- [AgentSmokeTest.php](../../app/Console/Commands/AgentSmokeTest.php) — smoke tests для pipeline

---

**Наступний документ**: [AI Інтеграція →](ai-integration.md)
