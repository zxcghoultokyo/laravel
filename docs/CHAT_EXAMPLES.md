# Приклади роботи AI Chat Agent

> **Актуально для:** FunctionCallingAgent + StreamingFunctionCallingAgent (OpenAI function calling)
> **Дата оновлення:** Березень 2026

## ⚡ Два режими відповіді

| Режим | Коли | Агент | Час відповіді |
|-------|------|-------|---------------|
| `short_query_handler` | 1 слово (без дієслова) | Bypass GPT | ~200ms |
| `GPT function calling` | 2+ слова або складні запити | FunctionCallingAgent / StreamingFunctionCallingAgent | ~1-3s |

---

## 🎯 Сценарій 1: Короткий запит (1 слово)

**Користувач пише:**
> шоломи

**Відповідь:**
```json
{
  "type": "products",
  "products": [{"title": "...", "price": ..., "images": [...]}],
  "meta": { "source": "short_query_handler" }
}
```

**Під капотом:**
```
short_query_handler:
├─ Meili search "шоломи" → candidates
├─ filterByTitleRelevance() → stem-based фільтр
├─ Limit: max 3 products
└─ Return з images (без GPT!)
```

**Інші приклади:**
- `плитоноски` → 3 товари
- `берці` → 3 товари
- `підсумки` → 3 товари
- `бронепластини` → 3 товари

---

## 🎯 Сценарій 2: Пошук з контекстом

**Користувач пише:**
> покажи тактичні штани

**Відповідь:**
```json
{
  "type": "products",
  "text": "Ось тактичні штани, які є в наявності:",
  "products": [{"title": "...", "price": ..., "images": [...]}],
  "meta": { "source": "GPT" }
}
```

**Під капотом:**
```
StreamingFunctionCallingAgent:
├─ System prompt (per-tenant via PromptPresetService)
├─ OpenAI function calling
│   └─ tool_call: search_products(query: "тактичні штани")
│       └─ MeiliProductSearchTool → фільтрація → max 3
├─ GPT generates intro text
└─ SSE streaming → widget
```

---

## 🎯 Сценарій 3: Запит з фільтрами

**Користувач пише:**
> плитоноска зелена до 5000 грн

**Під капотом:**
```
GPT → search_products(query: "плитоноска зелена", filters: {budget_max: 5000})
├─ MeiliProductSearchTool:
│   ├─ Meili search з фільтром price <= 5000
│   ├─ color filter "зелена" → green/olive/олива
│   └─ filterByTitleRelevance() → max 3
└─ GPT intro: "Ось плитоноски до 5000 ₴:"
```

**GPT автоматично витягує фільтри:**
- `"до 5000"` → `budget_max: 5000`
- `"зелена"` → `color: green`
- `"від 3000 до 8000"` → `budget_min: 3000, budget_max: 8000`

---

## 🎯 Сценарій 4: GPT відповідає з історії (без tool_calls)

**Контекст:** Користувач вже бачив товари, запитує уточнення

**Користувач пише:**
> розкажи про перший

**GPT знає товар з історії і відповідає JSON:**
```json
{"intro": "Ось підсумок:", "products": [{"article": "ab3-775", "comment": "Тактичний..."}]}
```

**Під капотом:**
```
GPT response (NO tool_calls):
├─ parseStructuredResponse($responseText)
│   ├─ Lookup products by article in DB
│   └─ Return з images для карток
├─ Extract intro/outro text
└─ Return products + text
```

> **ВАЖЛИВО:** Обидва агенти обробляють цей випадок в `else` гілці (коли немає tool_calls).

---

## 🎯 Сценарій 5: Follow-up "дорожче/дешевше"

**Користувач пише:**
> а дешевше є?

**Під капотом:**
```
GPT → search_products(query: "плитоноска", filters: {budget_max: <lower>})
├─ exclude_shown: [shown_ids from history]
├─ Нові товари (не ті що вже показав)
└─ Інтро: "Ось бюджетніші варіанти:"
```

---

## 🎯 Сценарій 6: Статус замовлення

**Користувач пише:**
> статус замовлення 0680001122

**Під капотом:**
```
GPT → get_order_status(phone: "0680001122")
├─ OrderSearchService → DB lookup
└─ Return order details
```

---

## 🎯 Сценарій 7: Привітання / Smalltalk

**Користувач пише:**
> привіт

**GPT відповідає** (з per-tenant промпта):
```
Привіт! Я AI-консультант магазину. Допоможу підібрати товар.
Що шукаєш?
```

---

## 🎯 Сценарій 8: Дитячий магазин — вікова фільтрація (bavkatoys)

**Користувач пише:**
> іграшки для дитини 3 років

**Під капотом:**
```
GPT → search_products(query: "іграшки 3+")
├─ handleAgeQuery() → вікова фільтрація
├─ filterByTitleRelevance() + вік 3+
└─ Max 3 products для дітей 3+
```

**Тактичний магазин (T2):** GPT **ніколи** не питає про вік дитини (заборонено в промпті).

---

## 📊 Ключові правила

### 1. Максимум 3 товари
GPT prompt: **показуй 1-3 товари**, не більше.

### 2. Per-tenant промпти
Кожен тенант має свій системний промпт через `PromptPresetService`:
- **T2 (attack.kiev.ua):** тактичне спорядження, без питань про вік
- **T20 (bavkatoys):** дитячі іграшки, вікова фільтрація

### 3. Два шляхи пошуку
```
search_products tool:
├─ Meilisearch (primary) → MeiliProductSearchTool
└─ Eloquent fallback (якщо Meili disabled) → LIKE queries
```

### 4. Товари ЗАВЖДИ першими
Навіть якщо запит неоднозначний — спочатку показуємо товари, потім питаємо уточнення.

---

## 📊 Типовий timing (production)

Короткий запит (1 слово):
```
Total: ~200ms
└─ Meili search + filter: 200ms
```

Звичайний запит (GPT):
```
Total: ~1.5-3s (streaming)
├─ OpenAI function call: ~800ms
├─ Meili search: ~50ms
├─ DB product details: ~20ms
└─ GPT response generation: ~700ms (streamed)
```

---

## 🧪 Тестування через API

```bash
# Тест будь-якого запиту (T2 attack.kiev.ua)
curl -s "https://aintento.laravel.cloud/api/chat" \
  -H "Content-Type: application/json" \
  -d '{"message": "шоломи", "session_id": "test_'$(date +%s)'", "token": "<WIDGET_TOKEN>"}' | python3 -c "
import sys,json
d=json.load(sys.stdin)
print('source:', d.get('meta',{}).get('source','GPT'))
print('type:', d.get('type'))
print('products:', len(d.get('products',[])))
print('text:', d.get('text','')[:150])
"
```

---

*Last updated: March 2026*
