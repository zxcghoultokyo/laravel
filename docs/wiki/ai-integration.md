# 🤖 AI Інтеграція — OpenAI & ChatGPT

> **Остання оновлення**: 22.12.2025  
> **Модель**: GPT-5.1  
> **Провайдер**: OpenAI API

---

## 📋 Зміст
1. [Огляд](#огляд)
2. [AiRouter — Центральний AI Клієнт](#airouter)
3. [Intent Classification](#intent-classification)
4. [Query Normalization](#query-normalization)
5. [AI Reranking](#ai-reranking)
6. [Error Handling & Fallbacks](#error-handling)

---

## Огляд

AI використовується на **3 ключових етапах**:

1. **Intent Classification** — розуміння що хоче юзер (product_search vs order_status vs faq)
2. **Query Normalization** — очищення запиту від шуму ("плитоноска зелена до 5000" → "плитоноска")
3. **AI Reranking** — інтелектуальна сортування товарів за релевантністю

---

## AiRouter

**Файл**: [app/Services/Ai/AiRouter.php](../../app/Services/Ai/AiRouter.php)

**Роль**: Єдина точка для всіх викликів OpenAI API.

### Конфігурація
```php
// config/services.php
'openai' => [
    'api_key' => env('OPENAI_API_KEY'),
    'model' => env('OPENAI_MODEL', 'gpt-5.1'),
    'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
],
```

### Основні Методи

#### 1. `callOpenAI(string $prompt, float $temperature, int $maxTokens)`
Базовий метод для всіх API calls.

```php
$response = $aiRouter->callOpenAI(
    prompt: "Translate to Ukrainian: Hello",
    temperature: 0.3,  // 0.0-1.0 (lower = more deterministic)
    maxTokens: 100
);
// Returns: string (raw OpenAI response)
```

**Error Handling**:
- Catch `\Exception` → log warning → return fallback
- Не падає якщо API недоступний

---

#### 2. `classify(string $message): array`
Визначає **інтенцію** користувача.

**Input**: "Шукаю плитоноску зелену"

**Output**:
```php
[
    'intent' => 'PRODUCT_SEARCH',  // uppercase (legacy)
    'confidence' => 0.95,
]
```

**Можливі інтенції**:
- `PRODUCT_SEARCH` — пошук товару
- `ORDER_STATUS` — статус замовлення
- `FAQ` — загальні питання (доставка, оплата, повернення)
- `SMALL_TALK` — привітання, подяки
- `FALLBACK` — невідомий тип запиту

**Промпт**:
```
Ти — AI-класифікатор інтенцій для військового магазину.

Запит: "{$message}"

Визнач інтенцію:
- PRODUCT_SEARCH — шукає товар
- ORDER_STATUS — питає про замовлення
- FAQ — загальні питання
- SMALL_TALK — вітання, подяки
- FALLBACK — невідомо

JSON: {"intent": "...", "confidence": 0.95}
```

**Fallback**: Якщо OpenAI недоступний → `PRODUCT_SEARCH` (safe default).

---

#### 3. `normalizeSearchQuery(string $message): string`
Очищає запит від шуму для Meilisearch.

**Input**: "плитоноска зелена мультикам до 5000 грн будь ласка"

**Output**: "плитоноска"

**Промпт**:
```
Normalize search query for product search.

Input: "{$message}"

Rules:
- Remove politeness words (будь ласка, дякую)
- Remove budget mentions (до 5000)
- Remove color/size (extract separately)
- Keep core product name

Return ONLY normalized query string (no JSON).
```

**Fallback**: Якщо OpenAI недоступний → повертає `$message` без змін.

**Note**: Фільтри (бюджет, колір) витягуються окремо в `AgentOrchestrator::extractFiltersFromMessage()`.

---

## Intent Classification

### Flow
```
User: "Шукаю плитоноску АТАКА"
    ↓
AiRouter::classify($message)
    ↓ OpenAI API call
{
    "intent": "PRODUCT_SEARCH",
    "confidence": 0.98
}
    ↓
AgentOrchestrator normalizes intent
    ↓ str_replace('_', '', strtolower($intent))
"productsearch"
    ↓
match($intent) {
    'productsearch' => handleProductSearch(),
    'orderstatus' => handleOrderStatus(),
    'faq' => handleFaq(),
    'smalltalk' => handleSmallTalk(),
    default => handleUnknown()
}
```

### Приклади Класифікації

| Запит | Intent | Confidence |
|-------|--------|------------|
| "Покажи плитоноски" | PRODUCT_SEARCH | 0.99 |
| "Де моє замовлення #12345?" | ORDER_STATUS | 0.97 |
| "Яка вартість доставки?" | FAQ | 0.92 |
| "Дякую!" | SMALL_TALK | 0.95 |
| "asdfghjkl" | FALLBACK | 0.50 |

---

## Query Normalization

### Навіщо?
Meilisearch працює краще з короткими ключовими запитами без noise words.

### Приклади

| Original Query | Normalized | Що прибрано |
|----------------|------------|-------------|
| "плитоноска зелена до 5000 грн" | "плитоноска" | колір, бюджет |
| "покажи шоломи будь ласка" | "шоломи" | ввічливість |
| "шукаю турнікет CAT Gen 7" | "турнікет CAT" | "шукаю" |
| "hoffmann патчі українські" | "hoffmann патчі" | "українські" (дублікат) |

### Коли НЕ спрацьовує
- OpenAI недоступний → fallback на original query (працює, але гірше)
- Занадто короткий запит (<3 символи) → повертається без змін

---

## AI Reranking

**Файл**: [app/Services/Agent/Tools/AiRerankTool.php](../../app/Services/Agent/Tools/AiRerankTool.php)

### Навіщо?
Meilisearch сортує по **keyword matching + popularity**. AI розуміє **семантичну релевантність**.

**Приклад**:
- Meilisearch: "шолом" → [Шолом MICH 2000, Кріплення для шолома, Чохол для шолома]
- AI Reranking: "шолом" → [Шолом MICH 2000] (видалив аксесуари)

### Flow
```
Input: 20-25 products from AccessoryFilterTool
    ↓
AiRerankTool::buildRerankPrompt()
    ↓ Форматує список товарів:
"ID 123: Плитоноска АТАКА | 12000 грн | Тактичне спорядження | Popular: 99"
"ID 456: Плитоноска KOMBAT UK | 8000 грн | Тактичне спорядження | Popular: 530"
...
    ↓
OpenAI API call (temperature=0.3)
    ↓ AI промпт:
"Обери 3-10 найрелевантніших товарів. 
Якість > кількість. 
Основні товари перші, аксесуари останні."
    ↓
AI Response:
{
    "chosen_ids": [123, 456, 789],
    "reasoning": {
        "123": "Точна відповідність, бренд АТАКА",
        "456": "Альтернатива дешевша",
        "789": "Третій варіант"
    }
}
    ↓
AiRerankTool::rerank() reorders
    ↓
Output: 3 products (AI обрав тільки 3, не 10)
```

### Промпт Особливості

**✅ Реалізовано**:
- "Якщо є 3-4 ідеальних + 6 посередніх → вибери тільки 3-4"
- "Основні товари (плитоноски, шоломи) перші, аксесуари (ремені, панелі) останні"
- Приклади: "шеврон група крові" → 4 шеврони (НЕ додавай MED, СБУ)
- "hoffmann" → тільки HOFFMANN товари (НЕ додавай інші бренди)

**⚠️ TODO (не закомічено)**:
- Інструкція "ПОВАЖАТИ БРЕНД навіть якщо popularity нижча"
- Зараз AI може переставити KOMBAT UK (popularity=530) вище HOFFMANN (popularity=99)

### Dynamic Limit (3-10)

AI **САМ вирішує** скільки товарів повертати:
- Якщо 3 ідеальних варіанти → поверне 3
- Якщо 10 добрих варіантів → поверне 10
- **НЕ заповнює до 10**, якщо релевантних менше

**Code**:
```php
// OLD (fixed):
return array_slice($reranked, 0, $limit); // Завжди 10

// NEW (dynamic):
return $reranked; // Скільки AI обрав (3-10)
```

---

## Error Handling & Fallbacks

### OpenAI API Недоступний

| Сервіс | Fallback |
|--------|----------|
| `classify()` | → `PRODUCT_SEARCH` (safe default) |
| `normalizeSearchQuery()` | → original `$message` |
| `rerank()` | → original order, slice first 3 |

**Log Level**: `warning` (не `error`, бо це не критично)

### Rate Limits
OpenAI має rate limits:
- Free tier: 3 RPM (requests per minute)
- Paid tier: 500+ RPM

**Рішення**:
- Temperature 0.3 (швидше response)
- Max tokens 200-500 (залежить від завдання)
- Cache результатів (TODO: implement caching for classify)

---

## Performance Metrics

| Operation | Time | Cost (approx) |
|-----------|------|---------------|
| `classify()` | ~300-500ms | $0.0009 |
| `normalizeSearchQuery()` | ~200-400ms | $0.0007 |
| `rerank()` | ~500-800ms | $0.003 |
| **TOTAL per search** | ~1-1.7s | **$0.005** |

**Note**: GPT-5.1 pricing: ~$0.03/1K input tokens, ~$0.06/1K output tokens (2-3x дорожче за GPT-4-turbo)

**Bottleneck**: AI Reranking (найдовший call)

**Optimization Ideas**:
1. Cache popular queries classifications
2. Batch reranking calls (якщо багато одночасних запитів)
3. Use faster model (gpt-3.5-turbo) для classification

---

## Code References

### Файли
- [AiRouter.php](../../app/Services/Ai/AiRouter.php) — центральний AI клієнт
- [AiRerankTool.php](../../app/Services/Agent/Tools/AiRerankTool.php) — AI переранкація
- [AgentOrchestrator.php](../../app/Services/Agent/AgentOrchestrator.php) — використовує AiRouter

### Config
- [config/services.php](../../config/services.php) — OpenAI credentials

### Environment Variables
```bash
OPENAI_API_KEY=sk-proj-...
OPENAI_MODEL=gpt-5.1
OPENAI_BASE_URL=https://api.openai.com/v1
```

---

## Відомі Проблеми

### 🔴 AI reranker не поважає бренд
**Статус**: ⚠️ Частково fixed (промпт оновлено, але не закомічено)  
**Деталі**: Див. [Known Issues](known-issues.md#ai-reranker-brand-priority)

### ⚠️ Rate limiting на production
**Проблема**: Free tier OpenAI = 3 RPM → недостатньо для production  
**Рішення**: Upgrade to paid tier ($5/mo) → 500 RPM

### 💡 Classification caching
**Проблема**: Однакові запити класифікуються повторно  
**Рішення**: Cache `classify()` result на 1 годину в Redis/Laravel Cache

---

**Наступний документ**: [Frontend Integration →](frontend-integration.md)
