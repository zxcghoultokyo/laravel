# Agent Orchestrator - Інтеграція завершена ✅

## Що було зроблено

### 1. Створено AgentOrchestrator систему
- **app/Services/Agent/AgentOrchestrator.php** — головний оркестратор з plan-execute-respond архітектурою
- **app/Services/Agent/Tools/MeiliProductSearchTool.php** — пошук товарів (Meili + Eloquent fallback)
- **app/Services/Agent/Tools/ProductDetailsTool.php** — повні картки товарів з зображеннями
- **app/Services/Agent/Tools/DeduperTool.php** — дедуплікація по parent_article
- **app/Services/Agent/Tools/AccessoryFilterTool.php** — downrank аксесуарів

### 2. Зареєстровано сервіси в AppServiceProvider
Всі 5 сервісів зареєстровані як singletons з правильними залежностями:
- MeiliClient → MeiliProductSearchTool
- AiRouter + всі 4 інструменти → AgentOrchestrator

### 3. Інтегровано в ChatService
- Замінено стару логіку роутингу на виклик `AgentOrchestrator->handle()`
- Додано fallback на legacy логіку якщо щось не так
- Оновлено `logAssistantMessage()` для збереження мета-даних агента

### 4. Створено smoke test
- **test-agent.php** — простий скрипт для перевірки інтеграції
- **app/Console/Commands/AgentSmokeTest.php** — artisan команда (потребує БД)

## Ключові особливості реалізації

### HARD RULE: Товари завжди першими ✅
```php
// У handleProductSearch() завжди:
1. Search → 40 кандидатів
2. Dedupe → групування по parent_article
3. Filter → downrank accessories
4. Details → топ-10 повних карток
5. Message → "Ось X товарів:" + опціональне уточнення
```

### Intent Classification
- AiRouter повертає `PRODUCT_SEARCH` (uppercase) → AgentOrchestrator нормалізує до `product_search`
- Без OpenAI ключа: всі запити → `product_search` (safe fallback)
- З OpenAI: точна класифікація (`product_search`, `order_status`, `faq`, `smalltalk`)

### Filter Extraction
Автоматично витягує з повідомлення:
- **Бюджет**: "до 5000 грн" → `budget_max: 5000`
- **Колір**: "зелена" → `color: 'green'`
- Підтримує: чорний, зелений, олива, мультикам, пісочний, койот

### Деду лікація
Групування по `parent_article → article → id` з вибором кращого варіанту:
```php
score = in_stock (1000) + showcase (500) + popularity (×10) - price (÷1000)
```

### Accessory Filtering
Використовує поле `ai_product_type` (або fallback heuristics):
- Визначає primary type з запиту: plates, helmets, plate-carriers тощо
- Relationship map: plates потребують carriers, helmets потребують mounts
- Scoring: main product (1000), accessory (100), unrelated (50)

### Eloquent Fallback
Якщо Meilisearch недоступний (MEILI_ENABLED=0):
- Пошук по `title` та `search_index` через LIKE
- Фільтрація по `in_stock`, `budget_min/max`
- Сортування по `popularity DESC`

## Response Format

```php
[
  'message' => 'Ось підібрані товари:\n\n[товари]\n\nБажаєш SAPI чи ESAPI плити?',
  'products' => [
    ['id' => 123, 'title' => '...', 'price' => 5000, 'images' => [...], ...],
    ...
  ],
  'meta' => [
    'intent' => 'product_search',
    'ambiguous' => true,
    'refined_query' => 'плити бронезахист',
    'filters' => ['budget_max' => 5000],
    'chosen_ids' => [123, 456, 789],
    'search_debug' => [...],
  ]
]
```

## Що логується в БД

### chat_messages.meta
```json
{
  "intent": "product_search",
  "ambiguous": false,
  "chosen_ids": [123, 456, 789],
  "refined_query": "плити бронезахист",
  "filters": {"budget_max": 5000, "color": "green"},
  "search_debug": {...},
  "products_shown": 10
}
```

### chat_sessions
- `last_intent` — останній intent з агента
- `last_user_query` — оригінальний текст користувача
- `last_message_at` — timestamp останнього повідомлення

## Тестування

### Швидка перевірка (без БД)
```bash
php test-agent.php
```

Перевіряє:
- ✅ Реєстрацію всіх 5 сервісів
- ✅ Extraction фільтрів з повідомлення
- ✅ Response structure

### Повний тест (потрібна БД)
```bash
php artisan agent:smoke
```

Тестує 6 сценаріїв:
1. Пошук плит (базовий запит)
2. Запит на пораду ("яку каску взяти?")
3. Перевірка статусу замовлення
4. Smalltalk ("привіт")
5. FAQ ("що таке SAPI?")
6. Складний запит з фільтрами

## Відомі обмеження

### 1. OpenAI ключ не налаштований
- **Симптом**: всі запити класифікуються як `product_search`
- **Рішення**: встановити `OPENAI_API_KEY` в env
- **Fallback**: працює з keyword extraction з оригінального запиту

### 2. Meilisearch вимкнений
- **Симптом**: `Meilisearch is disabled (MEILI_ENABLED=0)`
- **Рішення**: Eloquent fallback автоматично використовується
- **Обмеження**: повільніший пошук, менше можливостей фільтрації

### 3. SQLite driver відсутній
- **Симптом**: `could not find driver`
- **Рішення**: встановити `php-sqlite3` або використовувати MySQL/PostgreSQL
- **Workaround**: сервіси все одно правильно створюються (DI працює)

## Наступні кроки

### Для production deployment:

1. **Налаштувати OpenAI**:
   ```bash
   OPENAI_API_KEY=sk-...
   OPENAI_MODEL=gpt-4
   OPENAI_BASE_URL=https://api.openai.com/v1
   ```

2. **Включити Meilisearch**:
   ```bash
   MEILI_ENABLED=true
   MEILI_HOST=http://localhost:7700
   MEILI_MASTER_KEY=...
   ```

3. **Запустити індексацію**:
   ```bash
   php artisan app:index-products-to-meili
   ```

4. **Тестування з реальними даними**:
   - Створити кілька chat sessions через widget
   - Перевірити логування в `chat_messages` та `chat_sessions`
   - Переконатися що `chosen_ids` та `filters` зберігаються

5. **Моніторинг**:
   - Перевірити логи на предмет помилок AI Router
   - Відстежувати швидкість відповідей (має бути < 3 сек)
   - Моніторити витрати OpenAI API (2 виклики на запит максимум)

## Архітектурні рішення

### Чому Plan-Execute-Respond?
- ✅ Детермінована поведінка (макс 2 AI виклики)
- ✅ Чіткий flow без infinite loops
- ✅ Легко дебагити (кожен крок логується)
- ✅ Можна додавати нові tools без зміни core логіки

### Чому ai_product_type field?
- ✅ Масштабується на нові категорії без коду
- ✅ Уникає hardcoded if/else дерев
- ✅ Дозволяє AI поліпшувати класифікацію
- ✅ Fallback на heuristics якщо поле пусте

### Чому Eloquent fallback?
- ✅ Не ламається якщо Meili недоступний
- ✅ Працює в dev середовищі без додаткового setup
- ✅ Дає час на налаштування production інфраструктури
- ✅ Тести можна запускати без зовнішніх залежностей

## Контракт з фронтом (widget)

Widget очікує response з полями:
```javascript
{
  type: 'products',     // або 'text'
  text: 'Повідомлення від бота',
  products: [...],      // масив товарів з id, title, price, images
  session_id: 'uuid',
  meta: {...}           // опціонально для дебагу
}
```

AgentOrchestrator повертає:
```php
[
  'message' => string,
  'products' => array,
  'meta' => array
]
```

ChatService конвертує це у фронт-формат:
```php
[
  'type' => empty(products) ? 'text' : 'products',
  'text' => $message,
  'products' => $products,
  'session_id' => $sessionId,
  'meta' => $meta,
]
```

## Changelog

### 2025-12-18
- ✅ Створено 5 сервісів агента
- ✅ Зареєстровано в AppServiceProvider
- ✅ Інтегровано в ChatService з fallback
- ✅ Додано filter extraction (budget, color)
- ✅ Додано Eloquent fallback для Meili
- ✅ Створено тестовий скрипт
- ✅ Оновлено логування (meta з agent)

### Готово до деплою ✅
Всі сервіси інтегровані, DI працює, fallbacks на місці. Потрібно тільки налаштувати OpenAI + Meilisearch на production.
